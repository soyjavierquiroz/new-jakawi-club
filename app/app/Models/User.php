<?php

namespace App\Models;

use App\Support\Normalize;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

#[Fillable(['name', 'email', 'password', 'phone', 'city'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            $user->uuid ??= (string) Str::uuid();
            do {
                $code = strtoupper(Str::random(12));
            } while (static::where('referral_code', $code)->exists());
            $user->referral_code ??= $code;
        });

        static::updating(function (User $user): void {
            if ($user->getRawOriginal('global_subject_id') !== null && $user->isDirty('global_subject_id')) {
                throw new \LogicException('El sujeto global emitido por COM es permanente.');
            }
            foreach (['uuid', 'referral_code', 'referred_by_id', 'registration_origin'] as $attribute) {
                if ($user->isDirty($attribute)) {
                    throw new \LogicException('La identidad y el referente son permanentes.');
                }
            }
        });
    }

    protected function email(): Attribute
    {
        return Attribute::make(set: fn (mixed $value): string => Normalize::email($value));
    }

    protected function phone(): Attribute
    {
        return Attribute::make(set: fn (mixed $value): ?string => $value === null ? null : Normalize::phone($value));
    }

    public function invitees(): HasMany
    {
        return $this->hasMany(self::class, 'referred_by_id');
    }

    public function referralUrl(): string
    {
        return rtrim(config('app.url'), '/').'/r/'.$this->referral_code;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'privacy_accepted_at' => 'datetime',
            'activated_at' => 'datetime',
            'is_marketing_admin' => 'boolean',
            'registration_origin' => 'array',
        ];
    }
}
