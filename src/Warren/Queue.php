<?php

namespace TSterker\Hopper\Warren;

class Queue
{
    /** @var mixed[] */
    protected array $data;

    /** @param mixed[] $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /** @return mixed[]  */
    public function getRawData(): array
    {
        return $this->data;
    }

    public function getName(): string
    {
        return $this->data['name'];
    }

    /**
     * @return array<string, mixed>
     */
    public function getArguments(): array
    {
        return $this->data['arguments'];
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public function hasArgument(string $key, $value): bool
    {
        return ($this->getArguments()[$key] ?? null) === $value;
    }
}
