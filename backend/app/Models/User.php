<?php

namespace App\Models;

use App\Modules\Chat\Models\ChatMessage;
use App\Modules\Chat\Models\ChatParticipant;
use App\Modules\Chat\Models\MessageRead;
use App\Modules\Maintenance\Models\MaintenanceComment;
use App\Modules\Maintenance\Models\MaintenanceRequest;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    protected string $guard_name = 'web';

    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'provider',
        'provider_id',
        'photo_url',
        'stripe_connect_account_id',
        'stripe_connect_details_submitted',
        'stripe_connect_charges_enabled',
        'stripe_connect_payouts_enabled',
        'stripe_connect_onboarded_at',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'stripe_connect_details_submitted' => 'boolean',
        'stripe_connect_charges_enabled' => 'boolean',
        'stripe_connect_payouts_enabled' => 'boolean',
        'stripe_connect_onboarded_at' => 'datetime',
    ];

    public function profile()
    {
        return $this->hasOne(Profile::class);
    }

    public function sendPasswordResetNotification($token)
    {
        $this->notify(new \App\Notifications\ResetPasswordNotification($token));
    }

    // app/Models/User.php
    public function assignedProperties()
    {
        return $this->belongsToMany(
            Property::class,
            'property_tenant',
            'tenant_id',
            'property_id',

        )->withPivot([
            'id',
            'subscription_active',
            'subscription_cancel_at',
            'stripe_subscription_id',
            'security_deposit_amount',
            'security_deposit_status',
            'property_id',
        ])
            ->withTimestamps();
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function managedProperties()
    {
        return $this->hasMany(Property::class, 'manager_id');
    }

    public function maintenanceRequests()
    {
        return $this->hasMany(MaintenanceRequest::class, 'tenant_id');
    }

    public function assignedMaintenanceRequests()
    {
        return $this->hasMany(MaintenanceRequest::class, 'assigned_to');
    }

    public function maintenanceComments()
    {
        return $this->hasMany(MaintenanceComment::class);
    }

    public function isPropertyManager(): bool
    {
        return $this->hasRole('property_manager');
    }

    public function chatParticipants()
    {
        return $this->hasMany(ChatParticipant::class);
    }

    public function sentChatMessages()
    {
        return $this->hasMany(ChatMessage::class, 'sender_id');
    }

    public function chatMessageReads()
    {
        return $this->hasMany(MessageRead::class);
    }
}
