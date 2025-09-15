<?php

namespace App\Entity;

use App\Repository\CateringTransactionRepository;
use Doctrine\ORM\Mapping as ORM;
use DateTimeImmutable;
use Ramsey\Uuid\UuidInterface;

#[ORM\Entity(repositoryClass: CateringTransactionRepository::class)]
class CateringTransaction
{
    // Transaction types
    public const TYPE_PAYMENT_RECEIVED = 'payment_received';     // External payment received
    public const TYPE_ORDER_PAYMENT = 'order_payment';           // Payment for catering order
    public const TYPE_ORDER_REFUND = 'order_refund';            // Refund from catering order
    public const TYPE_CREDIT_ADJUSTMENT = 'credit_adjustment';   // Manual admin adjustment
    
    // Payment sources
    public const SOURCE_PAYPAL = 'paypal';
    public const SOURCE_REVOLUT = 'revolut';
    public const SOURCE_N26 = 'n26';
    public const SOURCE_SPARKASSE = 'sparkasse';
    public const SOURCE_MANUAL = 'manual_admin';
    public const SOURCE_SYSTEM = 'system';
    
    // Transaction status
    public const STATUS_PENDING = 'pending';        // Waiting for processing
    public const STATUS_MATCHED = 'matched';        // Matched to user, waiting for processing
    public const STATUS_PROCESSED = 'processed';    // Successfully processed
    public const STATUS_IGNORED = 'ignored';        // Marked as ignored/duplicate
    public const STATUS_FAILED = 'failed';          // Processing failed
    
    // Match confidence levels
    public const CONFIDENCE_HIGH = 'high';          // 90%+ automated match
    public const CONFIDENCE_MEDIUM = 'medium';      // 50-90% automated match  
    public const CONFIDENCE_LOW = 'low';            // <50% automated match
    public const CONFIDENCE_MANUAL = 'manual';      // Manually assigned

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid')]
    private ?UuidInterface $user = null;

    #[ORM\Column]
    private int $amount = 0; // Amount in cents

    #[ORM\Column(length: 3)]
    private string $currency = 'EUR';

    #[ORM\Column(length: 30)]
    private string $type = self::TYPE_PAYMENT_RECEIVED;

    #[ORM\Column(length: 20)]
    private string $source = self::SOURCE_MANUAL;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $externalId = null; // PayPal transaction ID, bank reference, etc.

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reference = null; // Payment reference/note from payer

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $payerName = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $payerEmail = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $matchConfidence = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $processingNotes = null;

    // For order-related transactions
    #[ORM\ManyToOne(targetEntity: CateringOrder::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?CateringOrder $cateringOrder = null;

    // For duplicate detection
    #[ORM\Column]
    private bool $isDuplicate = false;

    #[ORM\ManyToOne(targetEntity: self::class)]
    #[ORM\JoinColumn(nullable: true)]
    private ?CateringTransaction $duplicateOf = null;

    #[ORM\Column]
    private ?DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $processedAt = null;

    public function __construct()
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?UuidInterface
    {
        return $this->user;
    }

    public function setUser(?UuidInterface $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getAmount(): int
    {
        return $this->amount;
    }

    public function setAmount(int $amount): static
    {
        $this->amount = $amount;
        return $this;
    }

    public function getAmountInEur(): float
    {
        return $this->amount / 100;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = $currency;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        if ($status === self::STATUS_PROCESSED && !$this->processedAt) {
            $this->processedAt = new DateTimeImmutable();
        }
        return $this;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): static
    {
        $this->externalId = $externalId;
        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): static
    {
        $this->reference = $reference;
        return $this;
    }

    public function getPayerName(): ?string
    {
        return $this->payerName;
    }

    public function setPayerName(?string $payerName): static
    {
        $this->payerName = $payerName;
        return $this;
    }

    public function getPayerEmail(): ?string
    {
        return $this->payerEmail;
    }

    public function setPayerEmail(?string $payerEmail): static
    {
        $this->payerEmail = $payerEmail;
        return $this;
    }

    public function getMatchConfidence(): ?string
    {
        return $this->matchConfidence;
    }

    public function setMatchConfidence(?string $matchConfidence): static
    {
        $this->matchConfidence = $matchConfidence;
        return $this;
    }

    public function getProcessingNotes(): ?string
    {
        return $this->processingNotes;
    }

    public function setProcessingNotes(?string $processingNotes): static
    {
        $this->processingNotes = $processingNotes;
        return $this;
    }

    public function getCateringOrder(): ?CateringOrder
    {
        return $this->cateringOrder;
    }

    public function setCateringOrder(?CateringOrder $cateringOrder): static
    {
        $this->cateringOrder = $cateringOrder;
        return $this;
    }

    public function getIsDuplicate(): bool
    {
        return $this->isDuplicate;
    }

    public function setIsDuplicate(bool $isDuplicate): static
    {
        $this->isDuplicate = $isDuplicate;
        return $this;
    }

    public function getDuplicateOf(): ?self
    {
        return $this->duplicateOf;
    }

    public function setDuplicateOf(?self $duplicateOf): static
    {
        $this->duplicateOf = $duplicateOf;
        if ($duplicateOf) {
            $this->isDuplicate = true;
        }
        return $this;
    }

    public function getCreatedAt(): ?DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getProcessedAt(): ?DateTimeImmutable
    {
        return $this->processedAt;
    }

    public function setProcessedAt(?DateTimeImmutable $processedAt): static
    {
        $this->processedAt = $processedAt;
        return $this;
    }

    public function isProcessed(): bool
    {
        return $this->status === self::STATUS_PROCESSED;
    }

    public function isMatched(): bool
    {
        return $this->user !== null;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
