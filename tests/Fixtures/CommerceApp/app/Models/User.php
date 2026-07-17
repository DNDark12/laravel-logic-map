<?php

namespace Fixtures\CommerceApp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

final class User extends Model
{
    use Notifiable;
}
