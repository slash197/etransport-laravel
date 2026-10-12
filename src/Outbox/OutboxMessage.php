<?php

declare(strict_types=1);

namespace slash197\eTransport\Laravel\Outbox;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use slash197\eTransport\MessageType;

/**
 * A message uploaded to ANAF and what became of it.
 *
 * @property int                       $id
 * @property string                    $account       The key the TokenProvider is asked with
 * @property string                    $cif           The tax code the message was uploaded for
 * @property MessageType               $type
 * @property string|null               $uit           The UIT the message was about, or the one ANAF assigned
 * @property string|null               $upload_id     ANAF's id for the upload (index_incarcare)
 * @property string|null               $reference     The declarant's own reference (refDeclarant)
 * @property string|null               $subject_type
 * @property string|null               $subject_id
 * @property MessageStatus             $status
 * @property string                    $payload       The XML as uploaded, encrypted at rest
 * @property array<string, mixed>|null $meta
 * @property list<string>|null         $errors        Why ANAF refused the message, or why the upload or the last check failed; encrypted at rest
 * @property int                       $checks        How far along the poll schedule the message is
 * @property Carbon|null               $next_check_at
 * @property Carbon|null               $last_check_at
 * @property Carbon|null               $uploaded_at
 * @property Carbon|null               $resolved_at
 * @property Carbon|null               $created_at
 * @property Carbon|null               $updated_at
 * @property-read Model|null           $subject       The application's record the message is about
 */
class OutboxMessage extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        $table = config('etransport.outbox.table');

        return is_string($table) && $table !== '' ? $table : 'etransport_messages';
    }

    /**
     * The application's record the message is about, if one was given.
     *
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MessageType::class,
            'status' => MessageStatus::class,
            'payload' => 'encrypted',
            // What ANAF answers can quote the document back
            'errors' => 'encrypted:array',
            'meta' => 'array',
            'checks' => 'integer',
            'next_check_at' => 'datetime',
            'last_check_at' => 'datetime',
            'uploaded_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }
}
