<?php

namespace App\Entity;

use App\Repository\CateringBalanceRepository;
use Doctrine\ORM\Mapping as ORM;
use DateTimeImmutable;
use Ramsey\Uuid\UuidInterface;

#[ORM\Entity(repositoryClass: CateringBalanceRepository::class)]
class CateringBalance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    private ?UuidInterface $user = null;

    #[ORM\Column]
    private int $balance = 0; // Current balance in cents

    #[ORM\Column]
    private ?DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->updatedAt = new DateTimeImmutable();
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

    public function getBalance(): int
    {
        return $this->balance;
    }

    public function setBalance(int $balance): static
    {
        $this->balance = $balance;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getBalanceInEur(): float
    {
        return $this->balance / 100;
    }

    public function addBalance(int $amount): static
    {
        $this->balance += $amount;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function deductBalance(int $amount): static
    {
        $this->balance -= $amount;
        $this->updatedAt = new DateTimeImmutable();
        return $this;
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?DateTimeImmutable $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }
}
