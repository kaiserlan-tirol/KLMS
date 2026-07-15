<?php

namespace App\Entity;

use App\Repository\ShopAddonsRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ShopAddonsRepository::class)]
class ShopAddon
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column]
    private ?int $price = null;

    #[ORM\Column]
    private ?bool $active = null;

    #[ORM\Column(nullable: true)]
    private ?int $sortIndex = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private ?bool $onlyOnce = null;

    #[ORM\Column(nullable: true)]
    private ?int $maxQuantityGlobal = null;

    #[ORM\Column]
    private ?bool $onePerTicket = false;

    /** When this addon is selected on a ticket, the ticket's base price becomes 0. */
    #[ORM\Column(options: ['default' => false])]
    private bool $zerosTicketPrice = false;

    /** @var Collection<int, ShopAddon> Addons that become 0€ on the same ticket when this addon is selected. */
    #[ORM\ManyToMany(targetEntity: self::class)]
    #[ORM\JoinTable(name: 'shop_addon_zeros')]
    #[ORM\JoinColumn(name: 'addon_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'target_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $zerosAddons;

    /** @var Collection<int, ShopAddon> Addons that must be selected on the same ticket when this addon is selected. */
    #[ORM\ManyToMany(targetEntity: self::class)]
    #[ORM\JoinTable(name: 'shop_addon_requires')]
    #[ORM\JoinColumn(name: 'addon_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'target_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $requiresAddons;

    public function __construct()
    {
        $this->zerosAddons = new ArrayCollection();
        $this->requiresAddons = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getPrice(): ?int
    {
        return $this->price;
    }

    public function setPrice(int $price): static
    {
        $this->price = $price;

        return $this;
    }

    public function isActive(): ?bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getSortIndex(): ?int
    {
        return $this->sortIndex;
    }

    public function setSortIndex(int $sortIndex): static
    {
        $this->sortIndex = $sortIndex;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getOnlyOnce(): ?bool
    {
        return $this->onlyOnce;
    }

    public function setOnlyOnce(?bool $onlyOnce): static
    {
        $this->onlyOnce = $onlyOnce;

        return $this;
    }

    public function getMaxQuantityGlobal(): ?int
    {
        return $this->maxQuantityGlobal;
    }

    public function setMaxQuantityGlobal(?int $maxQuantityGlobal): static
    {
        $this->maxQuantityGlobal = $maxQuantityGlobal;
        return $this;
    }
    
    public function isOnePerTicket(): ?bool
    {
        return $this->onePerTicket;
    }

    public function setOnePerTicket(bool $onePerTicket): static
    {
        $this->onePerTicket = $onePerTicket;
        return $this;
    }

    public function isZerosTicketPrice(): bool
    {
        return $this->zerosTicketPrice;
    }

    public function setZerosTicketPrice(bool $zerosTicketPrice): static
    {
        $this->zerosTicketPrice = $zerosTicketPrice;
        return $this;
    }

    /**
     * @return Collection<int, ShopAddon>
     */
    public function getZerosAddons(): Collection
    {
        return $this->zerosAddons;
    }

    public function addZerosAddon(ShopAddon $addon): static
    {
        if (!$this->zerosAddons->contains($addon) && $addon !== $this) {
            $this->zerosAddons->add($addon);
        }
        return $this;
    }

    public function removeZerosAddon(ShopAddon $addon): static
    {
        $this->zerosAddons->removeElement($addon);
        return $this;
    }

    /**
     * @return Collection<int, ShopAddon>
     */
    public function getRequiresAddons(): Collection
    {
        return $this->requiresAddons;
    }

    public function addRequiresAddon(ShopAddon $addon): static
    {
        if (!$this->requiresAddons->contains($addon) && $addon !== $this) {
            $this->requiresAddons->add($addon);
        }
        return $this;
    }

    public function removeRequiresAddon(ShopAddon $addon): static
    {
        $this->requiresAddons->removeElement($addon);
        return $this;
    }

    /** Whether this addon zeroes the ticket price or other addons when selected. */
    public function isZeroTrigger(): bool
    {
        return $this->zerosTicketPrice || !$this->zerosAddons->isEmpty();
    }

    public function zerosAddon(ShopAddon $addon): bool
    {
        foreach ($this->zerosAddons as $target) {
            if ($target === $addon || ($target->getId() !== null && $target->getId() === $addon->getId())) {
                return true;
            }
        }
        return false;
    }
}
