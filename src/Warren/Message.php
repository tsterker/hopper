<?php

namespace TSterker\Hopper\Warren;

class Message
{
    /** @var mixed[] */
    protected array $data;

    /** @param mixed[] $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function getBody(): string
    {
        return $this->data['payload'];
    }

    /**
     * @return array<string, mixed>
     */
    public function getProperties(): array
    {
        return $this->data['properties'];
    }

    public function wasRedelivered(): bool
    {
        return ($this->data['redelivered'] ?? 0) > 0;
    }
}
