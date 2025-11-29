<?php

namespace App\Service;

use Endroid\QrCode\Builder\BuilderRegistryInterface;
use Psr\Log\LoggerInterface;

class CachedQrCodeService
{
    public function __construct(
        private readonly BuilderRegistryInterface $builderRegistry,
        private readonly LoggerInterface $logger
    ) {}

    private function getPublicDir(): string
    {
        return __DIR__ . '/../../public';
    }

    private function ensureDir(string $path): void
    {
        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }
    }

    /**
     * Generate (if missing) and return absolute URL to a cached SEPA catering QR code image.
     * Multiple identical amounts share the same file.
     * @param int $amountCents Positive amount in cents.
     */
    public function getSepaCateringQrUrl(int $amountCents): string
    {
        if ($amountCents < 0) { $amountCents = abs($amountCents); }
        $filename = sprintf('sepa-catering-%d.png', $amountCents);
        $dir = $this->getPublicDir() . '/qr';
        $this->ensureDir($dir);
        $filePath = $dir . '/' . $filename;

        if (!file_exists($filePath)) {
            try {
                $euros = number_format($amountCents / 100, 2, '.', '');
                $payload = 'BCD\n001\n1\nSCT\nREVOLT21\nMarkus Edenhauser\nLT863250018735876769\nEUR' . $euros . "\n\nCatering Guthaben Ausgleich";
                $builder = $this->builderRegistry->get('default');
                $options = ['data' => $payload];
                $result = $builder->build(...$options);
                file_put_contents($filePath, $result->getString());
            } catch (\Throwable $e) {
                $this->logger->error('Failed generating cached QR code', [
                    'amount_cents' => $amountCents,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $scheme = $_ENV['SITE_BASE_SCHEME'] ?? 'https';
        $host = $_ENV['SITE_BASE_HOST'] ?? ($_SERVER['HTTP_HOST'] ?? 'localhost');
        return sprintf('%s://%s/qr/%s', $scheme, $host, $filename);
    }
}
