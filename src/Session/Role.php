<?php

declare(strict_types=1);

namespace Porta\AssistPlugin\Session;

/** What a guest may do: read along, or edit too. The host decides, and may change it. */
enum Role: string
{
    case ReadOnly = 'read';
    case Edit = 'edit';
}
