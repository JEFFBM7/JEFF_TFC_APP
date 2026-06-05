Voici un PRD — Product Requirements Document adapté à votre application. Un PRD sert à préciser l’objectif du produit, ses fonctionnalités, son comportement attendu, ses utilisateurs, ses critères de succès et son périmètre, afin d’aligner les parties prenantes avant la conception et le développement.

PRD — Plateforme numérique de suivi scolaire et d’amélioration des performances des élèves

Cas : Complexe scolaire MALUNGA

1. Informations générales du produit
Élément	Description
Nom du produit	Plateforme numérique de suivi scolaire
Domaine	Éducation numérique / système d’information scolaire
Public cible	Administration scolaire, enseignants, parents, élèves
Établissement cible	Complexe scolaire MALUNGA
Version du PRD	1.0
Type de solution	Application web avec architecture client–serveur
Objectif principal	Centraliser les données scolaires et améliorer le suivi académique des élèves
2. Contexte et justification

Le Complexe scolaire MALUNGA fait face à des difficultés liées au suivi manuel ou fragmenté des informations scolaires, notamment les notes, les absences, les retards et les appréciations pédagogiques. Cette dispersion rend la collecte, le traitement et l’exploitation des données scolaires moins efficaces, ce qui limite le suivi continu des performances des élèves. Votre document de TFC présente justement la plateforme comme une réponse à ces insuffisances, en visant la centralisation des données, l’analyse des performances et le renforcement de la communication entre l’école et les parents.

Le produit à développer doit donc permettre à l’établissement de disposer d’un outil numérique capable de suivre les élèves de manière régulière, de produire des indicateurs fiables, de faciliter la communication avec les parents et d’aider les enseignants ainsi que l’administration à prendre des décisions pédagogiques plus rapides.

3. Problème à résoudre

Actuellement, le suivi scolaire repose encore sur des pratiques manuelles ou sur des supports dispersés. Cette situation entraîne plusieurs problèmes :

Problème observé	Conséquence
Données scolaires non centralisées	Difficulté à retrouver rapidement les informations d’un élève
Communication tardive avec les parents	Faible implication des parents dans le suivi des élèves
Absence d’indicateurs de performance	Détection tardive des élèves en difficulté
Gestion manuelle des notes et absences	Risque d’erreurs, lenteur et perte d’informations
Faible traçabilité des actions scolaires	Difficulté à suivre l’évolution d’un élève sur plusieurs périodes
4. Vision du produit

La plateforme numérique de suivi scolaire vise à devenir un système centralisé permettant au Complexe scolaire MALUNGA de gérer les informations académiques des élèves, de suivre leurs performances, de communiquer avec les parents et de produire des tableaux de bord utiles à la prise de décision.

La solution ne doit pas être une plateforme complète d’enseignement à distance. Elle doit prioritairement couvrir le suivi scolaire : élèves, enseignants, parents, notes, absences, bulletins, notifications et indicateurs de performance. Ce périmètre correspond à la délimitation fonctionnelle déjà retenue dans votre TFC.

5. Objectifs du produit
5.1 Objectif général

Concevoir et développer une plateforme numérique permettant d’assurer un suivi scolaire continu, fiable et centralisé des élèves du Complexe scolaire MALUNGA.

5.2 Objectifs spécifiques
Code	Objectif spécifique
OBJ-01	Centraliser les informations relatives aux élèves, enseignants, classes et parents
OBJ-02	Faciliter l’enregistrement, la consultation et le calcul des notes
OBJ-03	Assurer le suivi des absences et retards des élèves
OBJ-04	Générer les bulletins scolaires par trimestre
OBJ-05	Permettre aux parents de consulter les performances scolaires de leurs enfants
OBJ-06	Fournir des tableaux de bord aux enseignants et à l’administration
OBJ-07	Détecter plus tôt les élèves en difficulté
OBJ-08	Sécuriser l’accès aux données selon le profil de l’utilisateur
6. Utilisateurs cibles et rôles
Acteur	Besoins principaux	Accès attendu
Administrateur système	Paramétrer l’application, gérer les comptes et les droits	Accès complet
Direction / Administration scolaire	Suivre les classes, consulter les statistiques, valider les bulletins	Accès administratif
Enseignant	Encoder les notes, consulter les élèves, suivre les performances	Accès limité à ses classes/cours
Parent / Tuteur	Consulter les notes, absences, bulletins et notifications de son enfant	Accès limité à ses enfants
Élève	Consulter ses résultats, absences et observations	Accès personnel
Préfet / surveillant	Enregistrer les absences, retards et observations disciplinaires	Accès vie scolaire
7. Périmètre fonctionnel du MVP

Le MVP, c’est-à-dire la première version exploitable du produit, doit contenir les modules essentiels suivants :

Module	Description	Priorité
Authentification et gestion des rôles	Connexion sécurisée selon le profil utilisateur	Élevée
Gestion des élèves	Création, modification, consultation des fiches élèves	Élevée
Gestion des enseignants	Gestion des enseignants et attribution aux cours/classes	Élevée
Gestion des classes	Création des classes, affectation des élèves	Élevée
Gestion des parents	Liaison parent–élève	Élevée
Gestion des notes	Encodage, modification, calcul des moyennes	Élevée
Gestion des absences et retards	Enregistrement et consultation des présences	Élevée
Bulletins scolaires	Génération des bulletins trimestriels	Élevée
Tableau de bord	Indicateurs de performance, absences, évolution	Moyenne
Notifications	Alertes aux parents sur notes, absences ou bulletins	Moyenne
Rapports / exports	Export PDF ou Excel des bulletins et listes	Moyenne
8. Hors périmètre de la première version

Les éléments suivants ne seront pas inclus dans le MVP :

Élément exclu	Justification
Enseignement à distance complet	Le sujet porte sur le suivi scolaire, non sur l’e-learning
Création de contenus pédagogiques numériques	Fonctionnalité secondaire non prioritaire
Paiement scolaire en ligne	Peut être prévu dans une version future
Application mobile native Android/iOS	Une application web responsive est suffisante pour le MVP
Intelligence artificielle prédictive avancée	À envisager après constitution d’un historique fiable
Gestion nationale ou multi-écoles complexe	Le cas d’étude est limité au Complexe scolaire MALUNGA
9. Exigences fonctionnelles
9.1 Authentification et sécurité d’accès
ID	Exigence	Priorité	Critère d’acceptation
F-01	Le système doit permettre à chaque utilisateur de se connecter avec un identifiant et un mot de passe	Élevée	Un utilisateur valide accède à son tableau de bord
F-02	Le système doit attribuer des droits selon le rôle	Élevée	Un parent ne peut voir que les données de son enfant
F-03	Le système doit permettre la réinitialisation du mot de passe	Moyenne	Un utilisateur peut récupérer son accès en cas d’oubli
9.2 Gestion des élèves
ID	Exigence	Priorité	Critère d’acceptation
F-04	L’administration doit pouvoir créer une fiche élève	Élevée	Une fiche élève contient identité, classe, matricule, parent lié
F-05	L’administration doit pouvoir modifier les informations d’un élève	Élevée	Les modifications sont enregistrées et visibles
F-06	Le système doit permettre de rechercher un élève	Moyenne	Recherche possible par nom, matricule ou classe
9.3 Gestion des classes et cours
ID	Exigence	Priorité	Critère d’acceptation
F-07	Le système doit permettre de créer des classes	Élevée	Une classe peut être créée avec niveau et section
F-08	Le système doit affecter les élèves à une classe	Élevée	Chaque élève appartient à une classe
F-09	Le système doit affecter les enseignants aux cours	Élevée	Un enseignant ne voit que ses cours attribués
9.4 Gestion des notes
ID	Exigence	Priorité	Critère d’acceptation
F-10	L’enseignant doit pouvoir encoder les notes des élèves	Élevée	Les notes sont enregistrées par cours, période, trimestre et classe
F-11	Le système doit calculer automatiquement les moyennes	Élevée	Les moyennes sont calculées selon les coefficients définis
F-12	Le système doit signaler les élèves ayant une moyenne faible	Moyenne	Les élèves sous le seuil défini sont marqués comme à risque
F-13	L’administration doit pouvoir valider les notes avant publication	Moyenne	Les parents ne voient les notes qu’après validation

Moodle dispose déjà d’un carnet de notes permettant de centraliser les évaluations d’un cours, ce qui montre l’importance d’un module de notes structuré dans une plateforme éducative.

9.5 Gestion des absences et retards
ID	Exigence	Priorité	Critère d’acceptation
F-14	Le surveillant ou l’enseignant doit pouvoir enregistrer les absences	Élevée	Une absence est liée à un élève, une date et une période
F-15	Le système doit distinguer absence justifiée et non justifiée	Élevée	Le statut de l’absence est visible
F-16	Le système doit notifier les parents en cas d’absence répétée	Moyenne	Une alerte est envoyée selon un seuil défini
9.6 Communication école–parents
ID	Exigence	Priorité	Critère d’acceptation
F-17	Le parent doit pouvoir consulter les notes de son enfant	Élevée	Le parent voit les résultats publiés
F-18	Le parent doit pouvoir consulter les absences et retards	Élevée	Le parent voit l’historique de présence
F-19	Le système doit envoyer des notifications importantes	Moyenne	Le parent reçoit une notification pour note, absence ou bulletin
F-20	L’administration doit pouvoir envoyer une annonce aux parents	Moyenne	Les parents concernés reçoivent le message

Google Classroom propose des résumés envoyés aux représentants légaux, mais ces résumés n’incluent pas les notes, ce qui confirme l’intérêt de prévoir dans votre solution un accès parent plus complet aux résultats scolaires.

9.7 Bulletins scolaires
ID	Exigence	Priorité	Critère d’acceptation
F-21	Le système doit générer un bulletin par élève et par trimestre	Élevée	Un bulletin PDF trimestriel peut être généré
F-22	Le bulletin doit contenir les notes, moyennes, rang, appréciations et décision	Élevée	Les informations sont correctement affichées
F-23	L’administration doit pouvoir publier les bulletins aux parents	Moyenne	Le parent peut consulter le bulletin publié
9.8 Tableaux de bord et indicateurs
ID	Exigence	Priorité	Critère d’acceptation
F-24	La direction doit voir les statistiques globales	Moyenne	Nombre d’élèves, taux d’absence, moyennes par classe visibles
F-25	L’enseignant doit voir les performances de ses classes	Moyenne	Moyenne de classe et élèves faibles visibles
F-26	Le système doit afficher les élèves à risque	Moyenne	Un élève à faible moyenne ou absences répétées est signalé

L’UNESCO souligne que les évaluations fournissent des informations utiles pour améliorer la qualité de l’éducation, la pédagogie et les ressources éducatives ; cette logique justifie l’intégration d’indicateurs dans la plateforme.

10. Exigences non fonctionnelles
ID	Exigence	Description	Priorité
NF-01	Sécurité	Accès protégé par authentification et rôles	Élevée
NF-02	Confidentialité	Les données des élèves ne doivent être visibles qu’aux utilisateurs autorisés	Élevée
NF-03	Performance	Les pages principales doivent se charger rapidement dans des conditions normales	Moyenne
NF-04	Disponibilité	La plateforme doit être accessible pendant les heures scolaires et en dehors pour les parents	Moyenne
NF-05	Ergonomie	L’interface doit être simple pour les enseignants et parents	Élevée
NF-06	Compatibilité	L’application doit être utilisable sur ordinateur, tablette et smartphone	Élevée
NF-07	Traçabilité	Le système doit garder l’historique des modifications sensibles	Moyenne
NF-08	Sauvegarde	Une sauvegarde régulière de la base de données doit être prévue	Élevée
NF-09	Maintenabilité	Le code doit être organisé en modules séparés	Moyenne
NF-10	Évolutivité	L’architecture doit permettre l’ajout futur de modules	Moyenne

La CNIL rappelle que l’inscription et le suivi des élèves doivent respecter des règles de protection de la vie privée, particulièrement pour les plus jeunes ; votre PRD doit donc intégrer la confidentialité, le contrôle d’accès et la minimisation des données dès la conception.

11. Modèle de données conceptuel minimal
Entité	Description	Relations principales
Utilisateur	Compte de connexion	Associé à un rôle
Rôle	Administrateur, enseignant, parent, élève, direction	Définit les permissions
Élève	Informations scolaires de l’apprenant	Appartient à une classe, lié à un parent
Parent	Responsable de l’élève	Peut suivre un ou plusieurs élèves
Enseignant	Agent pédagogique	Enseigne un ou plusieurs cours
Classe	Regroupement d’élèves	Contient plusieurs élèves
Cours	Matière enseignée	Lié à une classe et un enseignant
Note	Résultat d’évaluation	Liée à élève, cours, période et trimestre
Absence	Présence ou absence d’un élève	Liée à élève, date, statut
Bulletin	Synthèse trimestrielle des résultats	Lié à élève et trimestre
Notification	Message envoyé à un utilisateur	Liée à utilisateur et événement

Organisation académique : une année scolaire contient 3 trimestres. Chaque trimestre contient 2 périodes, soit 6 périodes annuelles numérotées P1 à P6. La moyenne trimestrielle est calculée à partir des deux moyennes de période, et la moyenne annuelle à partir des trois moyennes trimestrielles.
12. Parcours utilisateurs principaux
Parcours 1 — Encodage des notes par l’enseignant
L’enseignant se connecte.
Il sélectionne une classe.
Il choisit un cours, un trimestre et une période.
Il encode les notes des élèves.
Il enregistre les résultats.
Le système calcule les moyennes.
L’administration valide les notes.
Les parents peuvent consulter les résultats publiés.
Parcours 2 — Consultation des résultats par un parent
Le parent se connecte.
Il sélectionne son enfant.
Il consulte les notes par période et les bulletins par trimestre.
Il consulte les absences et retards.
Il télécharge le bulletin si celui-ci est publié.
Il reçoit éventuellement des alertes en cas de difficulté scolaire.
Parcours 3 — Suivi des élèves à risque par la direction
La direction se connecte.
Elle accède au tableau de bord.
Elle filtre les élèves par classe, moyenne ou absences.
Elle identifie les élèves à risque.
Elle transmet l’information aux enseignants ou parents concernés.
Des actions pédagogiques peuvent être planifiées.
13. User stories prioritaires
ID	User story	Priorité
US-01	En tant qu’administrateur, je veux créer les comptes utilisateurs afin de contrôler l’accès au système	Élevée
US-02	En tant qu’administration, je veux enregistrer les élèves afin de constituer une base scolaire fiable	Élevée
US-03	En tant qu’enseignant, je veux encoder les notes afin de suivre les performances des élèves	Élevée
US-04	En tant que parent, je veux consulter les notes de mon enfant afin de l’accompagner à temps	Élevée
US-05	En tant que surveillant, je veux enregistrer les absences afin de suivre l’assiduité des élèves	Élevée
US-06	En tant que direction, je veux consulter les statistiques afin de prendre des décisions pédagogiques	Moyenne
US-07	En tant que parent, je veux recevoir une notification d’absence afin de réagir rapidement	Moyenne
US-08	En tant qu’administration, je veux générer les bulletins afin de réduire le travail manuel	Élevée
US-09	En tant qu’élève, je veux consulter mes résultats afin de suivre ma progression	Moyenne
US-10	En tant que direction, je veux identifier les élèves en difficulté afin d’organiser un accompagnement	Moyenne
14. Critères de succès du produit
Indicateur	Cible attendue
Taux d’utilisation par les enseignants	Au moins 80 % des enseignants utilisent la plateforme pour encoder les notes
Réduction du temps de production des bulletins	Réduction significative par rapport au processus manuel
Consultation parentale	Au moins 60 % des parents consultent les résultats pendant la période pilote
Fiabilité des données	Réduction des erreurs de saisie ou de perte de données
Détection des élèves à risque	Les élèves faibles ou très absents sont identifiables depuis le tableau de bord
Satisfaction utilisateur	Les utilisateurs pilotes jugent l’outil simple et utile
15. Contraintes
Type de contrainte	Description
Technique	L’application doit fonctionner sur une architecture client–serveur avec backend API, frontend et base de données relationnelle, conformément à l’orientation technique prévue dans votre TFC.
Organisationnelle	Les enseignants et agents administratifs doivent être formés à l’utilisation du système
Sécurité	Les données des élèves doivent être protégées par des droits d’accès stricts
Connectivité	L’application doit rester légère pour être utilisable dans un contexte de connexion limitée
Académique	Le projet doit être exploitable dans le cadre du TFC : cahier de charge, modélisation et implémentation sont attendus dans la démarche de réalisation d’une application.
16. Architecture fonctionnelle proposée
Couche	Description
Interface utilisateur	Tableaux de bord web pour administration, enseignants, parents et élèves
API backend	Services de gestion des utilisateurs, élèves, notes, absences, bulletins
Base de données	Stockage relationnel des données scolaires
Module sécurité	Authentification, rôles, permissions, journalisation
Module reporting	Génération de bulletins, statistiques, exports PDF/Excel
Module notification	Alertes internes ou messages aux parents
17. Priorisation MoSCoW
Catégorie	Fonctionnalités
Must have	Connexion, rôles, élèves, enseignants, classes, notes, absences, bulletins
Should have	Tableau de bord, notifications, exports PDF
Could have	Messagerie interne, historique détaillé, statistiques avancées
Won’t have pour MVP	Paiement scolaire, e-learning complet, application mobile native, IA prédictive
18. Risques et mesures d’atténuation
Risque	Impact	Mesure d’atténuation
Faible adoption par les enseignants	Données incomplètes	Formation courte et interface simple
Erreurs de saisie	Résultats incorrects	Validation, contrôles automatiques et historique
Accès non autorisé aux données	Atteinte à la confidentialité	Rôles, mots de passe, journalisation
Connexion Internet instable	Difficulté d’accès	Interface légère et possibilité future de mode offline
Mauvais paramétrage des périodes ou coefficients	Bulletins incorrects	Écran de paramétrage contrôlé par l’administration
Résistance au changement	Utilisation limitée	Phase pilote, accompagnement et feedback utilisateurs
19. Roadmap proposée
Phase	Objectif	Livrables
Phase 1 — Analyse	Identifier les besoins et les acteurs	Cahier de charge, exigences, cas d’utilisation
Phase 2 — Conception	Modéliser le système	Diagrammes UML, modèle de données, architecture
Phase 3 — Développement MVP	Construire les modules essentiels	Authentification, élèves, notes, absences, bulletins
Phase 4 — Tests	Vérifier le fonctionnement	Tests fonctionnels, corrections
Phase 5 — Pilote	Tester au Complexe scolaire MALUNGA	Retours utilisateurs, ajustements
Phase 6 — Amélioration	Ajouter les fonctions secondaires	Notifications avancées, statistiques, exports améliorés
