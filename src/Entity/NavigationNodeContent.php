<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class NavigationNodeContent extends NavigationNode
{
    #[ORM\ManyToOne(fetch: 'LAZY')]
    #[ORM\JoinColumn(name: 'content_id', referencedColumnName: 'id')]
    private ?Content $content = null;

    public function __construct(Content $content = null)
    {
        parent::__construct();
        $this->content = $content;
    }

    public function getContent(): ?Content
    {
        return $this->content;
    }

    public function setContent(Content $content): self
    {
        $this->content = $content;

        return $this;
    }

    public function getType(): ?string
    {
        return self::NAV_NODE_TYPE_CONTENT;
    }

    public function getTargetId(): ?int
    {
        if (is_null($this->content)) {
            throw new \Exception('Content is null for ' . $this->getId() . ' ' . $this->getName());
        }
        return $this->content->getId();
    }

    public function getTargetAlias(): ?string
    {
        return $this->content->getAlias();
    }
}