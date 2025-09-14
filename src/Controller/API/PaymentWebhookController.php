<?php

namespace App\Controller\API;

use App\Service\IncomingPaymentService;
use App\Service\PaymentMatchingService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class PaymentWebhookController extends AbstractController
{
    public function __construct(
        private IncomingPaymentService $incomingPaymentService,
        private PaymentMatchingService $paymentMatchingService,
        private LoggerInterface $logger
    ) {
    }


    #[Route('/secured-by-token/payment-webhook/{source}/debug', name: 'api_payment_webhook_debug', methods: ['POST'])]
    public function receivePaymentDebug(Request $request, string $source): JsonResponse
    {
        // Check authentication header
        $authHeader = $request->headers->get('X-Auth');
        $expectedToken = $this->getParameter('payment_webhook_token');

        // Process the payment webhook
        return new JsonResponse([
            'ip' => $request->getClientIp(),
            'user_agent' => $request->headers->get('User-Agent'),
            'source' => $source,
            'authHeader' => $authHeader,
            'expectedToken' => $expectedToken,
            'isAuthValid' => $authHeader && hash_equals($expectedToken, $authHeader),
            'hasAuthHeader' => !empty($authHeader),
            'tokensMatch' => $authHeader && hash_equals($expectedToken, $authHeader)
        ]);
    }
        

    #[Route('/secured-by-token/payment-webhook/{source}', name: 'api_payment_webhook', methods: ['POST'])]
    public function receivePayment(Request $request, string $source): JsonResponse
    {
        // Check authentication header
        $authHeader = $request->headers->get('X-Auth');
        $expectedToken = $this->getParameter('payment_webhook_token');
        
        if (!$authHeader || !hash_equals($expectedToken, $authHeader)) {
            $this->logger->warning('Payment webhook: Invalid authentication', [
                'ip' => $request->getClientIp(),
                'user_agent' => $request->headers->get('User-Agent'),
                'source' => $source
            ]);
            
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $content = $request->getContent();
            $data = json_decode($content, true);

            if (!$data || !isset($data['text'])) {
                return new JsonResponse(['error' => 'Invalid payload'], Response::HTTP_BAD_REQUEST);
            }

            $text = $data['text'];
            
            // Parse the payment message
            $paymentData = $this->parsePaymentMessage($text);
            
            if (!$paymentData) {
                $this->logger->info('Payment webhook: Message format not recognized', [
                    'text' => $text,
                    'source' => $source
                ]);
                
                return new JsonResponse(['error' => 'Message format not recognized'], Response::HTTP_BAD_REQUEST);
            }

            // Create the incoming payment
            $payment = $this->incomingPaymentService->createIncomingPayment(
                $paymentData['amount'] / 100, // Convert cents to euros
                'EUR',
                new \DateTimeImmutable(),
                ucfirst($source) . ' App Notification',
                [
                    'source' => 'mobile_webhook_' . $source,
                    'reference' => $text,
                    'payerName' => $paymentData['payer_name']
                ]
            );

            // Try to match the payment
            $this->paymentMatchingService->autoMatchPayment($payment);

            $this->logger->info('Payment webhook: Payment created successfully', [
                'payment_id' => $payment->getId(),
                'amount' => $paymentData['amount'],
                'payer_name' => $paymentData['payer_name'],
                'source' => $source
            ]);

            return new JsonResponse([
                'success' => true,
                'payment_id' => $payment->getId(),
                'amount' => $paymentData['amount'],
                'payer_name' => $paymentData['payer_name'],
                'source' => $source,
                'matched' => $payment->getMatchedUser() !== null
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Payment webhook: Error processing payment', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new JsonResponse([
                'error' => 'Internal server error'
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function parsePaymentMessage(string $text): ?array
    {
        // Pattern: "Du hast gerade 45€ von Firstname Lastname erhalten :money:"
        $pattern = '/^Du hast gerade (\d+(?:[,\.]\d{2})?)€ von (.+?) erhalten\s*:money:$/u';
        
        if (!preg_match($pattern, trim($text), $matches)) {
            return null;
        }

        $amountString = $matches[1];
        $payerName = trim($matches[2]);

        // Convert amount to cents (handle both comma and dot as decimal separator)
        $amountString = str_replace(',', '.', $amountString);
        $amountCents = (int) round(floatval($amountString) * 100);

        // Validate amount is reasonable (between 1 cent and 10000 EUR)
        if ($amountCents < 1 || $amountCents > 1000000) {
            return null;
        }

        // Validate payer name is reasonable
        if (empty($payerName) || strlen($payerName) > 100) {
            return null;
        }

        return [
            'amount' => $amountCents,
            'payer_name' => $payerName
        ];
    }
}
