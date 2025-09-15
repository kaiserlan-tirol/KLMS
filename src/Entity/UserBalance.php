<?php

namespace App\Entity;

use App\Repository\UserBalanceRepository;
use Doctrine\ORM\Mapping as ORM;
use DateTimeImmutable;
use Ramsey\Uuid\UuidInterface;

#[ORM\Entity(repositoryClass: UserBalanceRepository::class)]
class UserBalance
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'uuid', unique: true)]
    private ?UuidInterface $user = null;

    #[ORM\Column]
    private int $cateringBalance = 0; // Current catering credit in cents

    #[ORM\Column]
    private int $shopBalance = 0; // Current shop credit in cents (for future use)

    #[ORM\Column]
    private int $totalBalance = 0; // Total available balance in cents

    #[ORM\Column]
    private ?DateTimeImmutable $lastUpdated = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $notes = null;

    public function __construct()
    {
        $this->lastUpdated = new DateTimeImmutable();
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

    public function getCateringBalance(): int
    {
        return $this->cateringBalance;
    }

    public function setCateringBalance(int $cateringBalance): static
    {
        $this->cateringBalance = $cateringBalance;
        $this->updateTotalBalance();
        $this->touch();
        return $this;
    }

    public function getCateringBalanceInEuros(): float
    {
        return $this->cateringBalance / 100.0;
    }

    public function getShopBalance(): int
    {
        return $this->shopBalance;
    }

    public function setShopBalance(int $shopBalance): static
    {
        $this->shopBalance = $shopBalance;
        $this->updateTotalBalance();
        $this->touch();
        return $this;
    }

    public function getShopBalanceInEuros(): float
    {
        return $this->shopBalance / 100.0;
    }

    public function getTotalBalance(): int
    {
        return $this->totalBalance;
    }

    public function getTotalBalanceInEuros(): float
    {
        return $this->totalBalance / 100.0;
    }

    public function getLastUpdated(): ?DateTimeImmutable
    {
        return $this->lastUpdated;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): static
    {
        $this->notes = $notes;
        return $this;
    }

    public function addCateringCredit(int $amount): static
    {
        $this->cateringBalance += $amount;
        $this->updateTotalBalance();
        $this->touch();
        return $this;
    }

    public function deductCateringCredit(int $amount): static
    {
        $this->cateringBalance -= $amount;
        $this->updateTotalBalance();
        $this->touch();
        return $this;
    }

    public function addShopCredit(int $amount): static
    {
        $this->shopBalance += $amount;
        $this->updateTotalBalance();
        $this->touch();
        return $this;
    }

    public function deductShopCredit(int $amount): static
    {
        $this->shopBalance -= $amount;
        $this->updateTotalBalance();
        $this->touch();
        return $this;
    }

    private function updateTotalBalance(): void
    {
        $this->totalBalance = $this->cateringBalance + $this->shopBalance;
    }

    private function touch(): void
    {
        $this->lastUpdated = new DateTimeImmutable();
    }
}
