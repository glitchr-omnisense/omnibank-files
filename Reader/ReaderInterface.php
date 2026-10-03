<?php

namespace Omnibank\Files\Reader;

use Omnibank\Exception\ProviderException;
use Omnibank\Files\Statement;

/** Reads one kind of statement file. */
interface ReaderInterface
{
    /** The format's name, as a statement's "format" key gives it: "camt053", "ofx", "csv". */
    public function format(): string;

    /** Whether the file looks like this format: its content first, its name second. */
    public function accepts(string $content, string $name): bool;

    /**
     * @param array<string, mixed> $options the gateway's options, the statement's own over them
     *
     * @return list<Statement>
     *
     * @throws ProviderException when the file cannot be read
     */
    public function read(string $content, string $name, array $options = []): array;
}
