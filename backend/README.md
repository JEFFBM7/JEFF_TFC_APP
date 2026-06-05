<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

# EduConnect — API Backend (Laravel)

Ce répertoire contient le backend API RESTful pour la plateforme EduConnect (Complexe scolaire MALUNGA).

## Documentation API (Swagger)

La documentation de l'API est générée automatiquement à partir du code (sans annotations manuelles) grâce à `dedoc/scramble`.

- **Swagger UI (Interactive)** : Accessible sur `GET /api/documentation`
- **Spec OpenAPI 3.1 (JSON)** : Accessible sur `GET /docs/api.json` ou via le fichier `api.json` à la racine (généré avec `php artisan scramble:export`)

### Authentification dans Swagger UI
1. Utilisez l'endpoint `POST /api/v1/auth/login` (ex: `admin@educonnect.test` / `password`).
2. Copiez le token retourné.
3. Cliquez sur le bouton **Authorize** 🔓 en haut de la page Swagger UI.
4. Collez le token. Les endpoints protégés sont maintenant testables.

## Jeu de données de démonstration (DevSeeder)

Pour initialiser la base de données avec un jeu complet pour la soutenance (années, classes, élèves, parents, notes, absences) :

```bash
php artisan migrate:fresh --seed --seeder=DevSeeder
```

**Comptes de test générés :**
- Admin : `admin@educonnect.test`
- Enseignant : `mkabila@malunga.test`
- Secrétariat : `secretariat@malunga.test`
- Parent : `parent.tshimanga@test.com`
- Élève : `jean.tshimanga@eleve.malunga.test`
(Mot de passe pour tous : `password`)

## Programme scolaire RDC (génération manuelle)

Le bouton **Générer le programme scolaire** (onglet Cours) applique le catalogue officiel EPST/RDC aux divisions de l’année sélectionnée.

**Prérequis :** les classes de base et leurs divisions doivent exister (`POST /api/v1/school-years/{id}/generate-classes`).

**Périmètre admin :** un admin cycle ne traite que les divisions de son cycle ; l’admin général couvre toute l’année.

**Idempotence :** les matières déjà rattachées manuellement ne sont pas supprimées ; les coefficients sont complétés ou mis à jour selon la configuration `config/curriculum_rdc.php`.

---

## About Laravel
Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
