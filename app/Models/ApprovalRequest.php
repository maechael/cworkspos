<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A cashier's void or return waiting for, or given, the owner's approval. */
class ApprovalRequest extends Model
{
    /** A request nobody answered stops being approvable after this long; the cashier has to ask again. */
    public const EXPIRES_MINUTES = 15;

    public const TYPES = [
        'line_void' => 'Void a line',
        'clear_order' => 'Clear the order',
        'sale_void' => 'Void a sale',
        'return' => 'Return',
    ];

    protected $fillable = ['type', 'status', 'requested_by', 'sale_id', 'summary', 'amount', 'payload', 'decided_by', 'decided_via', 'decided_at', 'message'];

    protected $casts = [
        'amount' => 'decimal:2',
        'payload' => 'array',
        'decided_at' => 'datetime',
    ];

    public function requester()
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    /** Waiting and still young enough to approve. */
    public function scopeWaiting($query)
    {
        return $query->where('status', 'pending')->where('created_at', '>', now()->subMinutes(self::EXPIRES_MINUTES));
    }

    public function isWaiting(): bool
    {
        return $this->status === 'pending' && $this->created_at->gt(now()->subMinutes(self::EXPIRES_MINUTES));
    }

    /** Marks an old unanswered request expired, so everyone sees the same status. */
    public function settle(): self
    {
        if ($this->status === 'pending' && ! $this->isWaiting()) {
            $this->update(['status' => 'expired', 'message' => 'Nobody answered within '.self::EXPIRES_MINUTES.' minutes.']);
        }

        return $this;
    }

    public function typeLabel(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }

    public function viaLabel(): ?string
    {
        return ['remote' => "from the owner's login", 'password' => 'with their password at the counter', 'passkey' => 'with their passkey at the counter'][$this->decided_via] ?? null;
    }

    /** What the requester's screen needs to know. */
    public function forScreen(): array
    {
        $this->settle();

        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'summary' => $this->summary,
            'amount' => (float) $this->amount,
            'decided_by' => $this->decider?->name,
            'message' => $this->message,
        ];
    }
}
