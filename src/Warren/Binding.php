<?php

namespace TSterker\Hopper\Warren;

class Binding
{
    /** @var mixed[] */
    protected array $data;

    /** @param mixed[] $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function getSource(): string
    {
        return $this->data['source'];
    }

    public function getDestination(): string
    {
        return $this->data['destination'];
    }
}
