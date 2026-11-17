<?php

namespace App\Models;

use Laravel\Passport\HasApiTokens;
use Laravel\Passport\Contracts\OAuthenticatable;
use Illuminate\Foundation\Auth\User as Authenticatable;

class McpUser extends Authenticatable implements OAuthenticatable
{
    use HasApiTokens;

    protected $table = 'users';

    protected $hidden = ['password', 'remember_token'];
}
