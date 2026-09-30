<?php

namespace App\Enums;

/** Palier de compte, à valeur unique en v1 : cast de `users.plan`. */
enum Plan: string
{
    case Free = 'free';
}
