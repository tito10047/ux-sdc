<?php

namespace App\Component\Card;

use Tito10047\UX\Sdc\Attribute\AsSdcComponent;

// Name, template and assets are all derived from the class and its directory.
#[AsSdcComponent]
class Card
{
    public string $message;
}
