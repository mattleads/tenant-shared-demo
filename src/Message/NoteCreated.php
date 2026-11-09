<?php

namespace App\Message;

/** Handled asynchronously: the worker stamps the note with the process that handled it. */
final readonly class NoteCreated
{
    public function __construct(public int $noteId)
    {
    }
}
