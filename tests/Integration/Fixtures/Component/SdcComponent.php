<?php

namespace Tito10047\UX\Sdc\Tests\Integration\Fixtures\Component;

use Tito10047\UX\Sdc\Attribute\AsSdcComponent;

#[AsSdcComponent('SdcComponent', template: 'components/SdcComponent.html.twig', css: 'css/sdc.css', js: 'js/sdc.js')]
class SdcComponent
{
}
