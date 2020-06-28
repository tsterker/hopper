<?php

namespace TSterker\Hopper\Warren;

class Exchange
{
    /** @var mixed[] */
    protected array $data;

    /** @param mixed[] $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function getName(): string
    {
        return $this->data['name'];
    }
}
