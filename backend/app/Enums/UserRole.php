<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Enseignant = 'enseignant';
    case Parent = 'parent';
    case Eleve = 'eleve';
    case Secretariat = 'secretariat';
}
