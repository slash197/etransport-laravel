<?php

declare(strict_types=1);

namespace slash197\eTransport\Laravel\Outbox;

enum MessageStatus: string
{
    /** Being uploaded right now. A row left here by a dead process may or may not have reached ANAF. */
    case Uploading = 'uploading';

    /** Received by ANAF; its outcome is not known yet. */
    case Processing = 'processing';

    /** ANAF processed the message and found it valid. */
    case Accepted = 'accepted';

    /** ANAF processed the message and refused it. */
    case Rejected = 'rejected';

    /** The upload itself failed; ANAF did not take the message. */
    case Failed = 'failed';

    /** ANAF gave no outcome before the poll schedule ended. */
    case GivenUp = 'given_up';
}
