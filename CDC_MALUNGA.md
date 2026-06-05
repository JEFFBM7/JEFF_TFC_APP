# Cahier des charges — Plateforme EduConnect
**Complexe scolaire MALUNGA · Plateforme EduConnect · Système d'Information Scolaire (SIS)**

---

> **Document :** Cahier des charges technique et fonctionnel  
> **Version :** 1.1  
> **Date :** Avril 2026  
> **Statut :** En cours de validation  

---

## Table des matières

1. [Contexte et objectifs](#1-contexte-et-objectifs)
2. [Périmètre du projet](#2-périmètre-du-projet)
3. [Parties prenantes](#3-parties-prenantes)
4. [Besoins fonctionnels](#4-besoins-fonctionnels)
5. [Besoins non fonctionnels](#5-besoins-non-fonctionnels)
6. [Architecture technique et stack](#6-architecture-technique-et-stack)
7. [Modèle de données](#7-modèle-de-données)
8. [Fonctionnalités détaillées et cas d'usage](#8-fonctionnalités-détaillées-et-cas-dusage)
9. [Gestion des risques](#9-gestion-des-risques)
10. [Glossaire](#10-glossaire)

---

## 1. Contexte et objectifs

### 1.1 Contexte

Le Complexe scolaire MALUNGA gère aujourd'hui ses processus administratifs et pédagogiques de manière fragmentée : dossiers papier, tableurs non partagés, communications informelles. Cette dispersion entraîne des doublons, des pertes d'information et une communication école–famille insuffisante.

La mise en place d'un **Système d'Information Scolaire (SIS)** vise à centraliser l'ensemble de ces processus au sein d'une plateforme web unique, accessible à tous les acteurs (administration, enseignants, parents, élèves).

### 1.2 Objectifs du projet

| Priorité | Objectif |
|----------|----------|
| Haute | Numériser et centraliser les dossiers élèves |
| Haute | Automatiser la gestion des notes, bulletins et absences |
| Haute | Renforcer la communication école–famille (notifications, messagerie) |
| Moyenne | Fournir des tableaux de bord pour le pilotage pédagogique |
| Moyenne | Assurer la conformité RGPD et la traçabilité des données |
| Basse | Permettre l'interopérabilité avec des systèmes tiers (comptabilité, bibliothèque) |

### 1.3 Bénéfices attendus

- Réduction du temps de traitement administratif (inscriptions, bulletins, absences)
- Amélioration de la réactivité de la communication avec les familles
- Meilleure traçabilité des parcours élèves
- Accès aux données en temps réel pour la direction et les enseignants
- Base solide pour l'inspection scolaire et la conformité réglementaire

---

## 2. Périmètre du projet

### 2.1 Inclus dans le périmètre

- Portail web accessible depuis navigateur (PC, tablette, mobile)
- Gestion des utilisateurs avec quatre rôles : Administrateur, Enseignant, Parent, Élève
- Modules : élèves, enseignants, classes, matières, notes, absences, bulletins, messagerie, rapports
- Notifications automatiques (email et/ou SMS)
- Export PDF des bulletins scolaires
- Import CSV pour les données initiales

### 2.2 Hors périmètre (version 1)

- Application mobile native (iOS/Android)
- Module de comptabilité et paiement des frais de scolarité
- Gestion de bibliothèque
- Intégration LMS (Moodle, Google Classroom, etc.)

> Ces éléments pourront faire l'objet d'une version ultérieure (v2) selon les retours d'usage.

---

## 3. Parties prenantes

| Rôle | Acteur | Responsabilités |
|------|--------|-----------------|
| Commanditaire | Direction du Complexe MALUNGA | Validation des besoins, financement |
| Administrateur système (ATICE) | Responsable informatique | Configuration, maintenance, droits d'accès |
| Utilisateurs finaux | Enseignants, parents, élèves | Consultation, saisie de données |
| Équipe de développement | Développeurs, designer UX | Conception, développement, tests |
| Autorités scolaires | Inspection académique | Conformité, accès aux rapports |

---

## 4. Besoins fonctionnels

### 4.1 Gestion des utilisateurs et des rôles

- Inscription, connexion et déconnexion sécurisées
- Réinitialisation de mot de passe par email
- Quatre profils avec permissions différenciées :
  - **Administrateur** : accès complet à tous les modules
  - **Enseignant** : gestion de ses classes, saisie des notes et absences
  - **Parent** : consultation du suivi de son/ses enfant(s), messagerie
  - **Élève** : consultation de ses notes, bulletins et emploi du temps
- Journalisation des connexions et actions sensibles

### 4.2 Gestion des élèves

- Enregistrement des élèves (identité, date de naissance, contact parent, photo)
- Affectation à une classe et à un enseignant référent
- Dossier numérique unique par élève (historique scolaire, documents joints)
- Import en masse via fichier CSV ou Excel
- Recherche et filtrage par nom, classe, année scolaire

### 4.3 Gestion des enseignants

- Création et gestion des comptes enseignants
- Association à des matières et à des classes
- Fiche enseignant consultable par la direction

### 4.4 Gestion des matières et des classes

- Création des niveaux, sections et classes (ex. 6ème A, Terminale S)
- Définition des matières avec coefficients par classe
- Affectation de plusieurs enseignants à une même matière
- Gestion de l'emploi du temps (créneaux, salles)

### 4.5 Suivi de l'assiduité

- Saisie des présences et absences par cours et par journée
- Distinction absence justifiée / injustifiée
- Justification d'absence par le parent ou le secrétariat
- **Règle métier :** alerte automatique aux parents dès 3 absences injustifiées consécutives ou 5 sur une période de 30 jours
- Tableau récapitulatif de l'assiduité par élève et par classe

### 4.6 Gestion des notes et bulletins

- Saisie des notes par l'enseignant (matière, type d'évaluation, date, trimestre et période)
- Validation des notes dans l'intervalle [0–20]
- Calcul automatique des moyennes pondérées par coefficient
- Génération automatique du bulletin trimestriel (moyennes par matière, moyenne générale, appréciations)
- Ajout d'appréciations par l'enseignant principal
- Export du bulletin en PDF
- Envoi automatique par email aux parents à la clôture du trimestre

### 4.7 Rapports et analyses

- Tableau de bord global pour l'administrateur (effectifs, taux d'absences, moyennes générales)
- Tableau de bord enseignant (résultats par classe et par matière)
- Rapports exportables (PDF, CSV) :
  - Classement par moyenne au sein d'une classe
  - Taux d'absentéisme par classe et par période
  - Évolution des résultats sur plusieurs trimestres

### 4.8 Communication et notifications

- Messagerie interne entre enseignants, parents et administration
- Notifications automatiques par email (et optionnellement SMS) pour :
  - Seuil d'absentéisme atteint
  - Publication d'un bulletin
  - Convocation ou événement scolaire
  - Message reçu non lu
- Historique des notifications envoyées (consultable par l'administrateur)

### 4.9 Configuration et paramétrage (Administrateur)

- Définition des années scolaires, des trimestres et des 6 périodes annuelles (2 périodes par trimestre)
- Configuration des coefficients par matière et par niveau
- Gestion des seuils d'alerte (absentéisme, notes en dessous d'un seuil)
- Sauvegardes manuelles et automatiques de la base de données
- Archivage des données par année scolaire

---

## 5. Besoins non fonctionnels

### 5.1 Sécurité et confidentialité

- Chiffrement des mots de passe (bcrypt ou Argon2)
- Communication chiffrée via HTTPS (TLS 1.2 minimum)
- Authentification par token JWT avec expiration configurable
- Contrôle d'accès basé sur les rôles (RBAC)
- Conformité RGPD : traçabilité des accès, gestion du droit à l'oubli, consentements
- Journalisation des opérations critiques (modifications de notes, accès aux bulletins)

### 5.2 Performance

| Indicateur | Cible |
|------------|-------|
| Temps de chargement d'une page | < 2 secondes |
| Temps de réponse API | < 500 ms (95e percentile) |
| Utilisateurs simultanés supportés | ≥ 500 |
| Disponibilité | ≥ 99,5 % (hors maintenance planifiée) |

### 5.3 Ergonomie et accessibilité

- Interface responsive : compatible PC, tablette et smartphone
- Compatibilité navigateurs : Chrome, Firefox, Edge, Safari (versions récentes)
- Navigation intuitive, minimisant le nombre de clics pour les actions courantes
- Messages d'erreur clairs et contextualisés
- Support du français comme langue principale (multilingue envisageable en v2)

### 5.4 Scalabilité

- Architecture permettant le scaling horizontal (ajout de serveurs)
- Base de données optimisée avec index sur les colonnes fréquemment interrogées
- Mise en cache des données statiques (Redis ou équivalent)

### 5.5 Maintenabilité

- Code structuré selon une architecture MVC ou équivalente
- Documentation technique (README, commentaires, Swagger pour l'API)
- Tests unitaires et d'intégration avec couverture minimale de 70 %
- Utilisation d'outils CI/CD pour les déploiements automatisés

### 5.6 Interopérabilité

- API RESTful documentée (OpenAPI/Swagger)
- Import/export CSV et Excel pour les données élèves, notes et absences
- Préparation d'endpoints pour intégrations futures (comptabilité, LMS)

---

## 6. Architecture technique et stack

### 6.1 Architecture générale

La plateforme repose sur une architecture **client-serveur** en trois couches :

```
┌─────────────────────────────────────────────────────┐
│                   Client (SPA)                      │
│         Next.js / Vue.js (navigateur)               │
└────────────────────┬────────────────────────────────┘
                     │ HTTPS / API REST
┌────────────────────▼────────────────────────────────┐
│              Serveur Back-end (API)                 │
│         Node.js / Laravel / FastAPI                 │
│   Auth JWT · Logique métier · Notifications         │
└────────────────────┬────────────────────────────────┘
                     │
┌────────────────────▼────────────────────────────────┐
│           Base de données relationnelle             │
│         PostgreSQL + Redis (cache)                  │
└─────────────────────────────────────────────────────┘
```

Chaque composant est conteneurisé via **Docker**, déployé sur infrastructure cloud (AWS, Azure ou GCP), avec pipeline CI/CD (GitHub Actions ou GitLab CI).

### 6.2 Comparatif des stacks recommandées

| Stack | Usage cible | Avantages | Inconvénients |
|-------|-------------|-----------|---------------|
| **TypeScript + Next.js + Node.js + PostgreSQL** | SaaS, portail évolutif | Un seul langage, SSR performant, large écosystème | Configuration initiale plus complexe |
| **PHP Laravel + Vue.js + MySQL** | Applications métier, ERP scolaire | Développement rapide, stable, bien documenté | Moins adapté aux microservices |
| **Python FastAPI + React + PostgreSQL** | APIs haute performance, projets data/IA | Très performant, génération auto de la doc API | Nécessite une bonne maîtrise Python |

> **Recommandation :** Pour le Complexe MALUNGA, la stack **Laravel + Vue.js + PostgreSQL** est conseillée en priorité pour sa rapidité de développement, sa stabilité et sa facilité de maintenance par une équipe de taille réduite.

### 6.3 Services complémentaires

- **Authentification :** JWT + refresh token
- **Notifications email :** SMTP (Mailgun, SendGrid ou serveur propre)
- **Notifications SMS :** API tierce (Twilio, Africa's Talking)
- **Stockage de fichiers :** S3-compatible (AWS S3, MinIO en auto-hébergé)
- **Export PDF :** Bibliothèque côté serveur (wkhtmltopdf, Puppeteer, DomPDF)

### 6.4 Documentation de l'API (Swagger / OpenAPI)

L'API REST v1 est documentée automatiquement au format **OpenAPI 3.1**. La spec est générée à partir du code (FormRequest, Resources, signatures de controllers) via le package [`dedoc/scramble`](https://scramble.dedoc.co/) — aucune annotation manuelle n'est requise.

| Endpoint | Description |
|----------|-------------|
| `GET /api/documentation` | **UI Swagger officielle** (Swagger UI 5.x) — interface principale |
| `GET /docs/api.json` | Spec OpenAPI 3.1 brute (consommable par Postman, Insomnia, codegen, CI…) |
| `GET /docs/api` | UI Stoplight Elements (alternative incluse par Scramble) |

**Authentification dans Swagger UI :**
1. Appeler `POST /api/v1/auth/login` avec `{email, password}` depuis l'UI.
2. Copier le token Sanctum retourné.
3. Cliquer sur **Authorize** (cadenas en haut à droite) → coller le token dans le champ `bearerAuth`.
4. Toutes les routes protégées (badge cadenas) deviennent testables directement depuis l'UI.

> **Sécurité en production :** activer le middleware `Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess` dans `config/scramble.php` pour réserver l'accès à la doc aux administrateurs authentifiés.

**Régénération / export hors-ligne :**

```bash
php artisan scramble:export   # génère api.json à la racine du backend
php artisan scramble:analyze  # diagnostique les éventuels problèmes de génération
```

---

## 7. Modèle de données

### 7.1 Entités principales

```
utilisateurs        eleves              enseignants
──────────────      ──────────────      ──────────────
id (PK)             id (PK)             id (PK)
email               utilisateur_id FK   utilisateur_id FK
mot_de_passe        classe_id FK        specialite
role                parent_id FK        
nom                 date_naissance      parents
prenom              photo               ──────────────
                                        id (PK)
classes             matieres            utilisateur_id FK
──────────────      ──────────────      telephone
id (PK)             id (PK)             
nom                 nom                 
niveau              coefficient         
prof_principal FK                       

cours               notes               absences
──────────────      ──────────────      ──────────────
id (PK)             id (PK)             id (PK)
matiere_id FK       eleve_id FK         eleve_id FK
enseignant_id FK    matiere_id FK       cours_id FK
classe_id FK        valeur              date
horaire             type_eval           justifie (bool)
salle               trimestre           motif
                    date_saisie         

bulletins           messages
──────────────      ──────────────
id (PK)             id (PK)
eleve_id FK         expediteur_id FK
trimestre           destinataire_id FK
moyenne_generale    contenu
appreciations       date_envoi
date_generation     lu (bool)
```

### 7.2 Relations clés

- Un **élève** appartient à une **classe** et est rattaché à un **parent**
- Un **enseignant** enseigne une ou plusieurs **matières** dans une ou plusieurs **classes**
- Une **note** référence un **élève**, une **matière** et un trimestre
- Une **absence** est liée à un **élève** et à un **cours**
- Un **bulletin** agrège les notes d'un **élève** pour un trimestre donné

---

## 8. Fonctionnalités détaillées et cas d'usage

### UC-01 — Authentification

**Acteur :** Tout utilisateur  
**Précondition :** L'utilisateur possède un compte actif  
**Scénario nominal :**
1. L'utilisateur accède à la page de connexion
2. Il saisit son email et son mot de passe
3. Le système vérifie les identifiants et génère un token JWT
4. L'utilisateur est redirigé vers son tableau de bord selon son rôle

**Scénarios alternatifs :**
- Identifiants incorrects → message d'erreur, blocage après 5 tentatives
- Mot de passe oublié → envoi d'un lien de réinitialisation par email (valable 1h)

---

### UC-02 — Saisie des notes

**Acteur :** Enseignant  
**Précondition :** L'enseignant est connecté et affecté à la classe concernée  
**Scénario nominal :**
1. L'enseignant sélectionne une classe et une matière
2. Il choisit le type d'évaluation (devoir, examen, contrôle) et le trimestre
3. Il saisit une note pour chaque élève de la liste
4. Le système valide que chaque note est comprise entre 0 et 20
5. Les moyennes trimestrielles sont recalculées automatiquement
6. Une confirmation est affichée

**Règles métier :**
- Note hors intervalle [0–20] → rejet avec message explicite
- Modification d'une note déjà saisie → journalisation de la modification (ancienne valeur, nouvel utilisateur, date)

---

### UC-03 — Enregistrement des absences

**Acteur :** Enseignant  
**Scénario nominal :**
1. L'enseignant ouvre la liste de présence pour son cours du jour
2. Il coche les élèves absents
3. Le système enregistre les absences comme injustifiées par défaut
4. Si le seuil d'alerte est atteint, une notification est automatiquement envoyée aux parents concernés

**Scénario alternatif :**
- Le parent ou le secrétariat justifie l'absence ultérieurement via l'interface → statut mis à jour, notification annulée si applicable

---

### UC-04 — Génération et diffusion du bulletin

**Acteur :** Système (automatique) / Administrateur  
**Précondition :** Toutes les notes du trimestre ont été saisies et validées  
**Scénario nominal :**
1. L'administrateur clôture le trimestre depuis le module de paramétrage
2. Le système calcule les moyennes par matière et la moyenne générale pour chaque élève
3. L'enseignant principal ajoute ses appréciations (texte libre)
4. Le bulletin est généré en PDF
5. Une notification email est envoyée à chaque parent avec le bulletin en pièce jointe

---

### UC-05 — Suivi parental

**Acteur :** Parent  
**Scénario nominal :**
1. Le parent se connecte et accède au profil de son enfant
2. Il consulte les notes du trimestre en cours, les absences et les bulletins archivés
3. Il envoie un message à l'enseignant principal via la messagerie interne
4. Il reçoit une notification de réponse

---

### UC-06 — Import des données initiales

**Acteur :** Administrateur  
**Scénario nominal :**
1. L'administrateur télécharge le modèle CSV fourni par la plateforme
2. Il complète le fichier avec les données élèves/enseignants
3. Il importe le fichier depuis l'interface
4. Le système valide les données (doublons, champs obligatoires manquants)
5. Les comptes sont créés et les identifiants provisoires sont envoyés par email

---

## 9. Gestion des risques

| Risque | Probabilité | Impact | Mesure préventive |
|--------|-------------|--------|-------------------|
| Perte de données (panne serveur) | Faible | Élevé | Sauvegardes automatiques quotidiennes, réplication base de données |
| Accès non autorisé | Moyenne | Élevé | RBAC strict, journalisation, HTTPS, tokens à durée limitée |
| Faible adoption par les enseignants | Moyenne | Élevé | Formation utilisateur, interface simplifiée, support disponible |
| Surcharge en période de saisie | Faible | Moyen | Tests de charge, mise en cache, optimisation des requêtes |
| Non-conformité RGPD | Faible | Élevé | Audit juridique, politique de confidentialité, DPO désigné |
| Retard de livraison | Moyenne | Moyen | Découpage en phases, sprints courts, suivi hebdomadaire |

---

## 10. Glossaire

| Terme | Définition |
|-------|------------|
| **SIS** | Student Information System — Système d'Information Scolaire |
| **ATICE** | Animateur Technologique et Informatique de Circonscription de l'Éducation — désigne ici l'administrateur du système |
| **RGPD** | Règlement Général sur la Protection des Données (UE 2016/679) |
| **JWT** | JSON Web Token — mécanisme d'authentification sans état |
| **RBAC** | Role-Based Access Control — contrôle d'accès basé sur les rôles |
| **SPA** | Single Page Application — application web à page unique |
| **SSR** | Server-Side Rendering — rendu côté serveur |
| **CI/CD** | Continuous Integration / Continuous Deployment |
| **Trimestre** | Période scolaire de référence pour le calcul des moyennes et la génération des bulletins trimestriels |
| **Période** | Sous-division d'un trimestre ; chaque année scolaire contient 6 périodes numérotées P1 à P6 |
| **Coefficient** | Pondération appliquée à une matière dans le calcul de la moyenne générale |

---

*Document rédigé dans le cadre du projet EduConnect — Complexe scolaire · Version 1.1 · Avril 2026*
2. Besoins du système
2.1 Besoins fonctionnels
Gestion des utilisateurs et rôles : authentification sécurisée (inscription, login, réinitialisation de mot de passe), gestion des profils (Administrateur, Enseignant, Parent, Élève) et des permissions associées.
Gestion des élèves : enregistrement des élèves, affectation à une classe et à un enseignant référent. Chaque élève dispose d’un dossier numérique unique (identité, classe, antécédents)
.
Gestion des enseignants : création de comptes enseignants, association des enseignants à des matières et classes. Les enseignants peuvent être contactés via l’application.
Gestion des matières et classes : définition des matières enseignées et des classes (niveau, section). Possibilité d’affecter plusieurs enseignants à une même matière.
Inscriptions aux cours : inscription des élèves aux cours/activités, enregistrement des détails de l’enseignement (salle, horaire, etc.).
Suivi de l’assiduité : saisie des présences et absences des élèves par cours/jour. Gestion des justifications. Le système doit alerter automatiquement les parents au-delà de certains seuils d’absentéisme
.
Gestion des notes et bulletins : saisie des notes de chaque élève par matière et période rattachée à un trimestre, calcul des moyennes et génération automatique des bulletins scolaires trimestriels.
Rapports et analyses : génération de rapports et tableaux de bord (par classe, par matière, taux d’absentéisme, résultats par groupe) pour le suivi pédagogique
. Ces indicateurs aident la direction à prendre des décisions (orientation des élèves, remédiation).
Communication scolaire : module de messagerie interne et/ou notifications (email, SMS) entre les enseignants, les parents et l’administration. Par exemple, envoi de messages ciblés aux familles (circulaires, convocations, alertes). Un SIS efficace inclut cette communication intégrée pour simplifier les échanges
.
Gestion de comptes administratifs : pour l’administrateur du système (ATICE), fonctions de configuration (trimestres, périodes, années scolaires, coefficients, etc.), gestion des droits d’accès, et maintenance (sauvegardes, archivage).
Note : Ces besoins fonctionnels sont inspirés des « fonctions clés » d’un SIS moderne : dossier scolaire centralisé, gestion de l’assiduité, communication structurée et reporting
.

2.2 Besoins non fonctionnels
Sécurité et confidentialité : le système doit garantir la protection des données personnelles (chiffrement des mots de passe, protocole HTTPS, chiffrement de la base de données). La conformité au RGPD est exigée (traçabilité des accès, consentements, gestion des droits à l’oubli)
. L’authentification multi-facteurs ou la journalisation des accès critiques sont à prévoir.
Performance : l’application doit être rapide et réactive. Les temps de réponse (navigation, consultation de bulletins) doivent rester courts même en cas de charge élevée (plusieurs centaines d’utilisateurs simultanés). Des exigences de performance (par exemple, temps de chargement < 2s) doivent être définies pour garantir une expérience fluide
.
Disponibilité et fiabilité : le service doit être disponible 24h/24 (avec maintenance programmée) et tolérer les pannes matérielles (redondance, sauvegardes automatiques). Les NFR assurent une stabilité robuste du système
 (pour éviter les interruptions en période scolaire critique).
Ergonomie et accessibilité : l’interface utilisateur doit être simple, intuitive et conviviale, afin de minimiser l’effort des utilisateurs (enseignants comme parents)
. L’application sera responsive (compatible PC, tablette, mobile) et multi-navigateurs (Chrome, Firefox, Edge, Safari)
 pour permettre une consultation flexible (y compris via smartphone)
.
Scalabilité : la plateforme doit pouvoir s’adapter à l’augmentation du nombre d’utilisateurs (élèves, utilisateurs) ou de volumes de données sans dégrader les performances. L’architecture envisagée (monolithe modulable ou microservices) doit faciliter la montée en charge (scaling horizontal/vertical)
.
Maintenabilité : le code et la documentation doivent être structurés (ex. architecture MVC, commentaires) pour permettre des évolutions futures. L’utilisation de frameworks standards facilite la maintenance et la réutilisation
.
Interopérabilité : si nécessaire, prévoir la capacité d’intégration avec d’autres systèmes (export/import CSV/Excel, API tierces, facturation).
Contexte NFR : Comme le souligne Visure Solutions, les exigences non fonctionnelles dictent la qualité du système (performance, sécurité, convivialité, évolutivité) et influencent directement l’architecture choisie
. Elles garantissent que l’application ne se contente pas de fonctionner, mais excelle dans des conditions réelles.



 Fonctionnalités détaillées et cas d’usage
Authentification & sécurité : Inscription et connexion sécurisées (emails vérifiés, mots de passe hachés). Les mots de passe sont stockés en haché (bcrypt, Argon2). Implémentation d’une gestion de sessions ou tokens JWT. Exemples : connexion d’un enseignant via email/mot de passe, redirection sur son tableau de bord.
Tableau de bord selon rôle :
Administrateur : aperçu global (effectifs, absences du jour, alertes), accès à tous les modules (gestion utilisateurs, classes, etc.).
Enseignant : liste de ses classes, statistiques par classe (moyennes, présences), formulaires de saisie de notes/absences.
Parent : suivi de son(ses) enfant(s) (bulletins, absences, messages reçus), planning et événements, possibilité de contacter un enseignant via la messagerie.
Gestion des élèves et classes : L’administrateur crée les classes (ex. 6ème A, Terminale S) et inscrit des élèves via un formulaire de saisie (ou import CSV). Les enseignants peuvent consulter la liste des élèves par classe. Cas d’usage : l’ATICE ajoute un nouvel élève et l’affecte à la classe 3ème B.
Saisie des notes : L’enseignant choisit une classe et une matière, puis saisit les notes des élèves pour un devoir/examen. Un contrôle vérifie que la note est dans l’intervalle valide. Les moyennes sont recalculées automatiquement. Cas d’usage : un professeur d’histoire saisit les notes du dernier DS pour 2de C ; les moyennes trimestrielles sont mises à jour.
Gestion des absences : Pour chaque cours, l’enseignant coche les élèves absents. Les absences peuvent être justifiées ultérieurement par le parent via l’interface ou le secrétariat. Cas d’usage : le professeur principal enregistre les absences du premier cours du jour et fait une relance automatique aux parents des élèves absents injustifiés.
Bulletin et rapports : À la fin de chaque trimestre, l’application génère un bulletin regroupant les moyennes par matière et la moyenne générale des deux périodes du trimestre. La moyenne trimestrielle est calculée à partir des deux moyennes de période, puis la moyenne annuelle à partir des trois trimestres. L’enseignant principal peut ajouter des appréciations. Les bulletins peuvent être exportés en PDF et distribués (impression ou envoi électronique). Cas d’usage : le système compile les notes du 1er trimestre et envoie les bulletins aux parents par email.
Communication et notifications : Les utilisateurs peuvent s’envoyer des messages internes (ex. enseignant → parent), ou le système envoie automatiquement des notifications (SMS/email) pour les événements critiques : absences fréquentes, rendez-vous parents, retards aux paiements de scolarité, etc. Cas d’usage : la plateforme envoie automatiquement un email à un parent si l’enfant a plus de 3 absences non justifiées en une semaine.
Import/Export et intégrations : Pour faciliter le déploiement, possibilité d’importer des données initiales (élèves, enseignants, classes) via CSV ou fichier Excel. Export des données sous divers formats (PDF, CSV) pour archivage ou statistiques externes. Éventuellement, une API pour interagir avec d’autres systèmes (comptabilité, bibliothèque).
Paramétrage : L’administrateur définit des paramètres globaux : périodes scolaires, coefficients de matières, taux de présence minimale, etc. Cas d’usage : mise à jour de la grille des coefficients suite à une réforme.
Ces fonctionnalités illustrent tous les cas d’usage majeurs du système. Chaque fonctionnalité implique des cas d’usage précis (ajout d’un élève, saisie d’une note, génération de rapport, etc.) qui seront décrits en détail dans les spécifications fonctionnelles.
