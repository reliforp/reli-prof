<?php

declare(strict_types=1);

use Amp\Sync\Channel;

return function (Channel $channel): void {
    $channel->send(getmypid());
};
