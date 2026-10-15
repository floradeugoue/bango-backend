# Inventaire Exhaustif des Modules Frontend — Application Mobile BANGO

**Projet :** BANGO Mobile (React Native / Expo / TypeScript)  
**Date de l'audit :** Octobre 2026  
**Sources analysées :**
- Répertoire `app/` (routes expo-router et écrans)
- Répertoire `components/` (composants métier et formulaires)
- Répertoire `lib/api/` (contrats de services, mocks locaux, types d'entrée/sortie)
- Répertoire `lib/db/` (persistance locale SQLite / KV store)
- Spécifications `NAVIGATION.md`, `API-CONTRAT.md`, `CLAUDE.md`, `docs/API_CONTRACTS.md`

---

## 1. Vue d'Ensemble des Modules Frontend

L'architecture fonctionnelle de l'application mobile BANGO se structure autour de **21 modules fonctionnels et transverses** couvrant 384 écrans/frames répertoriés dans le design system.

| Code Module | Nom du Module | Rôle Métier Principal | Nombre d'Écrans Clés |
| :--- | :--- | :--- | :--- |
| **SP** | Splash & Lancement | Premier démarrage, onboarding applicatif introductif | 4 |
| **AU** | Authentification & Entrée | Inscription, connexion, 2FA, OTP téléphone, réinitialisation | 30 |
| **LG** | Légal, RGPD & Droits | CGU, vérification d'âge, consentement pub/KYC, export/suppression | 18 |
| **SQ** | Le Square | Fil d'actualité de quartier, interactions sociales, commentaires | 15 |
| **RL** | Reels | Lecteur vertical vidéo, sonothèque, interactions courtes | 9 |
| **CR** | Création de Contenu | Studio de composition (Square CR1, Reels CR2, Stories CR3, Immo CR4) | 48 |
| **IM** | L'Immo (Consultation) | Découverte immobilière, feed vidéo, carte, visites, filtres | 38 |
| **BL** | Espace Bailleur | Gestion du patrimoine, annonces, demandes de visite, créneaux | 17 |
| **AG** | Espace Agent Immobilier | Portefeuille de mandats, missions terrain, relations bailleurs | 6 |
| **BO** | Boost & Publicité | Sponsoring de publications et reels, ciblage, budget, portée | 9 |
| **P / AB** | Abonnements & Badges | Cycle de vie des badges Bailleur/Agent, rappels, renouvellement | 17 |
| **PG** | Pages Professionnelles | Présence d'entreprise/agence, certification, rôles administrateurs | 8 |
| **MS** | Messagerie | Chat direct, contextes d'annonces, groupes, vocaux, demandes | 21 |
| **RC** | Recherche & Tendances | Exploration tous contenus : profils, publications, biens, tags | 11 |
| **PR** | Profil Utilisateur | Mon profil, profils tiers, abonnements, badges, QR Code | 17 |
| **ST** | Paramètres & Sécurité | Sécurité du compte, 2FA, sessions actives, alertes intrusion | 20 |
| **PAY** | Paiement Mobile Money | Passerelle USSD, opérateurs, reçus vérifiables, portefeuille | 23 |
| **SUP** | Support & Modération | Centre d'aide, FAQ, tickets d'assistance, signalements, recours | 25 |
| **SY** | États Système | Mode hors-ligne, synchronisation, reprise d'upload, erreurs | 24 |
| **EDGE** | Cas Limites Transverses | Gestion des ruptures : bien disparu, agent révoqué, doublons | 24 |

---

## 2. Inventaire Détaillé par Module

---

### Module 1 : SP — Splash & Lancement

- **Rôle métier :** Accueillir l'utilisateur, détecter la langue de l'appareil, présenter les 3 piliers de la proposition de valeur BANGO (Immobilier vérifié, Réseau de quartier, Paiement sécurisé).
- **Sous-modules :**
  1. *Écran Splash* : initialisation de l'application, contrôle des mises à jour obligatoires.
  2. *Carrousel Onboarding* : diapositives explicatives (1 sur 3, 2 sur 3, 3 sur 3).
- **Fonctionnalités identifiables :**
  - Affichage de l'identité visuelle de la marque.
  - Détection automatique de la langue locale (`fr` / `en`).
  - Navigation par balayage vers l'écran d'entrée (connexion / inscription).
- **Fichiers frontend :**
  - `app/index.tsx`
  - `app/(auth)/language.tsx`
  - `app/(auth)/welcome.tsx`
  - `app/(auth)/onboarding.tsx`
- **Opérations de données nécessaires :**
  - Lecture des préférences locales (langue choisie).
  - Contrôle de version applicative minimale requise.

---

### Module 2 : AU — Authentification & Onboarding

- **Rôle métier :** Assurer l'enregistrement progressif (parcours en 6 étapes), l'authentification sécurisée, la validation des coordonnées et la récupération de compte.
- **Sous-modules :**
  1. *Création de compte (Credentials)* : email/numéro, mot de passe avec règles de robustesse.
  2. *Identité & Profil* : date de naissance, genre (avec option de masquage public), pays, ville et quartier.
  3. *Vérification téléphonique (OTP)* : envoi de SMS/appel, saisie du code à 6 chiffres, gestion de l'expiration et renvois.
  4. *Pseudo & Avatar* : choix du pseudonyme `@handle` avec vérification de disponibilité en temps réel, photo de profil.
  5. *Centres d'intérêt* : sélection minimale de 3 thématiques.
  6. *Préférences de recherche immobilière* : intention (recherche active / simple veille / aucune), budget, devise, typologie de bien.
  7. *Connexion & Sécurité* : authentification par identifiant/mot de passe ou SSO (Google / Apple), détection de compte suspendu ou supprimé (période de grâce de 30 jours).
  8. *Mot de passe oublié* : demande par email ou SMS masqué, validation OTP, réinitialisation avec interdiction de réutiliser les 3 anciens mots de passe.
  9. *Reprise d'onboarding* : détection et restauration d'un onboarding interrompu (`AU-22`).
- **Fonctionnalités identifiables :**
  - Validation interactive de mot de passe (8 car., majuscule, chiffre, caractère spécial).
  - Détection de doublon d'email (`AU-04`) et de numéro de téléphone (`AU-14`).
  - Compteur d'échecs de connexion (5 tentatives -> blocage 15 minutes, `AU-25`).
  - Détection des comptes suspendus (`AU-26`) avec renvoi vers le dossier de modération.
  - Détection des comptes supprimés (`AU-27`) avec réactivation automatique sous 30 jours.
- **Fichiers frontend :**
  - `app/(auth)/sign-up.tsx`, `app/(auth)/sign-in.tsx`
  - `app/(auth)/identity.tsx`, `app/(auth)/gender.tsx`, `app/(auth)/place.tsx`, `app/(auth)/country.tsx`, `app/(auth)/age.tsx`
  - `app/(auth)/phone.tsx`, `app/(auth)/otp.tsx`
  - `app/(auth)/interests.tsx`, `app/(auth)/housing.tsx`, `app/(auth)/friends.tsx`
  - `app/(auth)/forgot-password.tsx`, `app/(auth)/reset-password.tsx`
  - `app/(auth)/suspended.tsx`, `app/(auth)/deleted.tsx`, `app/(auth)/resume.tsx`
  - `lib/api/auth.ts`, `lib/api/otp.ts`, `lib/api/profile.ts`, `lib/api/housing.ts`, `lib/api/interests.ts`
- **Opérations de données nécessaires :**
  - Vérification de l'unicité de l'identifiant, email, téléphone.
  - Génération, envoi, contrôle et limitation de fréquence des codes OTP.
  - Émission de tokens d'accès et rafraîchissement de session.
  - Enregistrement de l'état d'avancement de l'onboarding (`completedSteps`, `updatedAt`).

---

### Module 3 : LG — Légal, RGPD, KYC & Droits

- **Rôle métier :** Garantir la conformité réglementaire, le recueil des consentements éclairés, la protection des données personnelles (RGPD) et la modération des atteintes aux droits.
- **Sous-modules :**
  1. *Consentements contractuels* : acceptation des CGU, politique de confidentialité, politique de contenu.
  2. *Contrôle d'âge* : vérification de majorité (≥ 18 ans), blocage des mineurs (`LG-09`).
  3. *Consentement KYC* : engagement avant transmission de pièces officielles pour les badges Bailleur/Agent.
  4. *Ciblage publicitaire* : consentement optionnel au profilage publicitaire (`LG-06`, `ST-15`).
  5. *Droits RGPD* : demande d'export d'archive des données personnelles (`LG-10` à `LG-12`), suppression définitive du compte avec délai de rétractation de 30 jours (`LG-13` à `LG-15`).
  6. *Propriété intellectuelle* : signalement formel pour atteinte aux droits d'auteur ou usurpation (`LG-17`).
- **Fonctionnalités identifiables :**
  - Versionnage des CGU avec obligation de ré-acceptation bloquante en cas de mise à jour (`LG-07`).
  - Génération asynchrone d'une archive ZIP des données utilisateur avec notification de mise à disposition.
  - Double confirmation de suppression de compte et invalidation des sessions.
- **Fichiers frontend :**
  - `app/(auth)/consent.tsx`, `app/(auth)/legal.tsx`, `app/(auth)/age-blocked.tsx`
  - `app/kyc-consent.tsx`, `app/kyc-documents.tsx`
  - `app/ads-consent.tsx`, `app/terms-update.tsx`, `app/legal-notices.tsx`, `app/ip-report.tsx`
  - `app/data-export.tsx`, `app/account-delete.tsx`
  - `lib/api/legal.ts`, `lib/api/dataExport.ts`, `lib/api/adTargeting.ts`
- **Opérations de données nécessaires :**
  - Récupération du contenu légal versionné et horodaté.
  - Enregistrement de l'acceptation des versions légales par l'utilisateur connecté.
  - Déclenchement du job d'export de données et génération de lien de téléchargement.
  - Mise en corbeille (soft delete) programmée à J+30 pour les comptes.

---

### Module 4 : SQ — Le Square (Fil d'Actualité de Quartier)

- **Rôle métier :** Offrir un flux social hyper-local ancré sur le quartier et la ville de l'utilisateur, valorisant les interactions citoyennes, les annonces et la vie locale.
- **Sous-modules :**
  1. *Fil principal* : flux paginé avec sélecteur de portée (Quartier / Abonnements / Découverte).
  2. *Détail d'une publication* : carrousel de médias, légende, mentions, hashtags, géolocalisation.
  3. *Commentaires & Réponses* : arborescence de discussion, réactions, contrôle de fermeture des commentaires par l'auteur.
  4. *Interactions sociales* : J'aime (décompte atomique), partage de lien, identification d'utilisateurs.
  5. *Modération du contenu* : signalement de publication avec sélection de motif et masquage immédiat.
  6. *Gestion de sa publication* : modification de la légende, modification de l'audience, archivage, désarchivage, suppression.
- **Fonctionnalités identifiables :**
  - Rail horizontal supérieur pour les Stories actives.
  - Bouton d'action flottant ou raccourci de création de post.
  - Gestion des états vides (`SQ-02`) et mise en cache hors-ligne (`SQ-03`).
  - Mise en sourdine d'un auteur sans se désabonner (`SQ-13`).
  - Consultation de la liste des personnes ayant aimé la publication (`SQ-14`).
- **Fichiers frontend :**
  - `app/(tabs)/square.tsx`
  - `app/post.tsx`, `app/caption-edit.tsx`, `app/likes.tsx`
  - `components/square/` (PostCard, CommentSheet, AudienceSheet, ShareModal, LikeButton)
  - `lib/api/square.ts`, `lib/api/social.ts`
- **Opérations de données nécessaires :**
  - Requête paginée du feed filtrée par portée géographique (`neighbourhood`, `following`, `discover`).
  - CRUD des publications (texte, médias, mentions, tags, localisation).
  - Gestion des commentaires (création, édition, suppression, réactions).
  - Gestion des mentions "J'aime" et statistiques de partage.
  - Archivage / désarchivage de posts.

---

### Module 5 : RL — Reels (Vidéos Courtes)

- **Rôle métier :** Fournir une expérience immersive de vidéo verticale plein écran, valorisant les créateurs locaux, les visites de biens et les animations du quartier.
- **Sous-modules :**
  1. *Lecteur Reel* : défilement vertical continu, lecture automatique, préchargement, bouton son.
  2. *Interactions sociales spécifiques* : Like / Dislike mutuellement exclusifs, partage, sauvegarde dans les favoris.
  3. *Commentaires de Reel* : panneau latéral coulissant de discussion avec réponses et réactions.
  4. *Page de Son / Audio* : fiche descriptive du morceau, bouton de mise en favori, liste des Reels utilisant cet extrait sonore.
  5. *Menu d'action & Recommandation* : option « Pas intéressé » pour affiner l'algorithme, signalement de vidéo.
  6. *Grille Explorer* : grille vidéo des Reels tendances.
- **Fonctionnalités identifiables :**
  - Badge cliquable reliant un Reel à une annonce immobilière (`RL-02`).
  - Mode économie de données (adaptation de qualité vidéo 480p / 720p).
  - Sous-titres automatiques synchronisés (`RL-09`).
- **Fichiers frontend :**
  - `app/(tabs)/reels.tsx`
  - `app/explore.tsx`, `app/sound.tsx`
  - `components/reels/` (ReelPlayer, SoundSheet, CommentsModal, InteractionBar)
  - `lib/api/reels.ts`, `lib/api/playback.ts`
- **Opérations de données nécessaires :**
  - Flux infini de vidéos courtes avec algorithme de recommandation et historique de visionnage.
  - Enregistrement des interactions (vue unique dédupliquée, like, dislike, sauvegarde).
  - Consultation et favoris sur le catalogue des pistes audio.
  - Gestion des commentaires dédiés aux vidéos courtes.

---

### Module 6 : CR — Création de Contenu (Studio Multimédia)

- **Rôle métier :** Outil unifié de création de contenus pour les 4 typologies de publication BANGO : Post Square (CR1), Reel (CR2), Story (CR3) et Annonce Immobilière (CR4).
- **Sous-modules :**
  1. *CR1 — Création Square* : sélection galerie, recadrage/filtres, carrousel d'images (ordre/couverture), rédaction de légende, mentions `@`, hashtags `#`, lieu géographique, choix de l'audience.
  2. *CR2 — Studio Reel* : enregistrement par segments (caméra plein écran), import galerie, découpe fine (poignées de trim), bibliothèque de sons, stickers/GIF, voix off, mixage audio, choix de la couverture vidéo, publication asynchrone en arrière-plan.
  3. *CR3 — Studio Story* : caméra instantanée, diapositives éphémères (24h), stickers interactifs (sondage, localisation, mention), choix d'audience (tout le monde, abonnés, liste choisie "amis proches"), archivage automatique, statistiques de consultation (spectateurs, votes, réponses).
  4. *CR4 — Annonce Immobilière (Réservé Bailleur/Agent)* : tournage guidé pièce par pièce avec consignes de cadrage, sélection jusqu'à 20 photos avec qualification par pièce (salon, chambre, cuisine, salle d'eau, extérieur), caractéristiques techniques, décomposition du loyer et des charges, quartier sans adresse exacte, prévisualisation prospect, soumission à modération.
  5. *Gestion des Brouillons* : persistance locale et reprise de montage (`CR-14`).
- **Fonctionnalités identifiables :**
  - Contrôle des métriques vidéo (durée min/max, orientation portrait obligatoire, ratio de luminosité).
  - Calculateur financier intégré pour les loyers, charges et cautions.
  - Mode d'envoi en arrière-plan avec barre de progression et reprise sur incident.
- **Fichiers frontend :**
  - `app/compose.tsx`, `app/drafts.tsx`, `app/uploads.tsx`
  - `app/create/pick.tsx`, `app/create/edit.tsx`, `app/create/order.tsx`, `app/create/tag.tsx`, `app/create/place.tsx`, `app/create/settings.tsx`, `app/create/preview.tsx`
  - `app/reel-studio.tsx`, `app/reel/sounds.tsx`, `app/reel/trim.tsx`, `app/reel/text.tsx`, `app/reel/voice.tsx`, `app/reel/cover.tsx`, `app/reel/publish.tsx`
  - `app/story-camera.tsx`, `app/story-editor.tsx`, `app/story-audience.tsx`, `app/story-close-list.tsx`, `app/story-mine.tsx`, `app/story-stats.tsx`, `app/story-archive.tsx`
  - `app/listing-new.tsx`, `app/listing-edit.tsx`
  - `lib/api/compose.ts`, `lib/api/stories.ts`, `lib/api/reels.ts`, `lib/api/listingForm.ts`, `lib/api/listingMedia.ts`, `lib/api/listingPhotos.ts`, `lib/api/listingPricing.ts`
- **Opérations de données nécessaires :**
  - Téléversement direct sécurisé de médias (images, vidéos, fichiers audio).
  - Sauvegarde et restauration de brouillons multi-segments.
  - Publication avec métadonnées complexes (stickers, géolocalisation, prix, charges).
  - Gestion des listes d'audience personnalisées ("liste choisie").

---

### Module 7 : IM — L'Immo (Recherche & Consultation)

- **Rôle métier :** Permettre aux particuliers de rechercher un logement vérifié à louer, acheter ou partager, de planifier et payer une visite en direct, et de configurer des alertes.
- **Sous-modules :**
  1. *Feed vertical immersif* : défilement d'annonces avec visites vidéo intégrées, statut de disponibilité ("Disponible" ou "Déjà loué/Occupé").
  2. *Bascule Liste / Carte* : consultation sur carte interactive vectorielle avec regroupement par grappes (clusters).
  3. *Moteur de filtres* : type de contrat (location, vente, colocation), fourchette de prix avec devise, charges incluses/exclues, nombre de pièces, équipements certifiés, visite préalable par un agent.
  4. *Fiche détaillée du bien* : diaporama photos par pièces, vidéo guidée, caractéristiques complètes, montant d'entrée détaillé (loyer, caution, avance, frais de dossier), géolocalisation au niveau quartier.
  5. *Réservation de visite* : consultation du calendrier de disponibilité du bailleur, choix d'un créneau, paiement des frais de dossier de visite via Mobile Money, confirmation et génération de reçu.
  6. *Gestion de mes visites* : historique des visites programmées, confirmation d'adresse exacte post-paiement, annulation et gestion du remboursement.
  7. *Biens enregistrés (Favoris)* : mise en favori pour consultation ultérieure.
  8. *Alertes de recherche* : création d'alertes automatiques basées sur les critères de recherche pour notification push.
- **Fonctionnalités identifiables :**
  - Suggestions d'élargissement de critères quand la recherche ne donne aucun résultat (`IM-04`).
  - Signalement spécifique "Bien déjà loué" par les prospects (`IM-29`).
- **Fichiers frontend :**
  - `app/(tabs)/immo.tsx`
  - `app/listing.tsx`, `app/immo-filters.tsx`, `app/alert-new.tsx`, `app/saved.tsx`
  - `app/booking.tsx`, `app/slots.tsx`, `app/visit.tsx`, `app/visits.tsx`
  - `components/immo/` (ListingCard, MapView, FilterModal, SlotPicker, VisitCard)
  - `lib/api/immo.ts`, `lib/api/visits.ts`
- **Opérations de données nécessaires :**
  - Requêtes de recherche géo-spatiales et par emprise de carte (`bounds`).
  - Filtrage multi-critères et agrégation en temps réel du nombre de résultats (`countMatching`).
  - Consultation des disponibilités de créneaux d'un bien.
  - Réservation transactionnelle de créneaux avec pose de verrou temporaire (`holding`).
  - CRUD des favoris et des alertes de recherche.

---

### Module 8 : BL — Espace Bailleur

- **Rôle métier :** Tableau de bord professionnel permettant au propriétaire vérifié de gérer son parc de logements, de suivre ses prospects, de planifier les visites et d'analyser la performance de ses annonces.
- **Sous-modules :**
  1. *Souscription au Badge Bailleur* : dossier d'éligibilité, dépôt de pièces d'identité et de propriété (KYC), paiement de la souscription.
  2. *Tableau de bord de gestion* : synthèse du portefeuille, alertes sur les demandes d'action urgentes.
  3. *Gestion des biens* : liste de ses biens, modification, changement de statut (`published`, `reserved`, `taken`), dépublication temporaire, suppression.
  4. *Planning des visites* : configuration des plages horaires d'ouverture aux visites, traitement des demandes de visite entrantes (accepter, décaler, refuser).
  5. *Statistiques de consultation* : tableau de bord analytique par bien (vues de la vidéo, enregistrements favoris, contacts messagerie, demandes de visite).
  6. *Délégation aux agents* : liste des agents autorisés à faire visiter le bien avec contrôle de leurs prérogatives (`BL-22`).
  7. *Encaissements* : suivi des recettes générées par les frais de visite et reçus associés.
- **Fonctionnalités identifiables :**
  - Marquer un bien comme "Loué" avec arrêt automatique des campagnes de boost actives et notification aux prospects en attente (`BL-10`).
- **Fichiers frontend :**
  - `app/landlord.tsx`, `app/landlord-join.tsx`
  - `app/listing-stats.tsx`, `app/listing-access.tsx`, `app/mark-taken.tsx`
  - `app/visit-requests.tsx`
  - `components/landlord/` (PropertyCard, AccessManager, StatsChart, SlotManager)
  - `lib/api/landlord.ts`, `lib/api/listingActivity.ts`
- **Opérations de données nécessaires :**
  - Récupération de la liste des biens de l'utilisateur connecté avec leurs compteurs.
  - Mise à jour du statut opérationnel d'un bien.
  - Configuration des créneaux d'ouverture et validation des demandes de visite.
  - Récupération des métriques statistiques agrégées.

---

### Module 9 : AG — Espace Agent Immobilier

- **Rôle métier :** Permettre aux agents de terrain certifiés de gérer leur portefeuille de délégations confiées par les bailleurs, de conduire les visites et de prospecter.
- **Sous-modules :**
  1. *Souscription au Badge Agent* : vérification professionnelle, carte d'agent, validation.
  2. *Portefeuille de mandats* : vue des biens confiés avec détail des capacités accordées (`visits`, `reply`, `edit`).
  3. *Invitations de bailleurs* : réception, examen du périmètre, acceptation ou refus d'un mandat (`AG-04`).
  4. *Gestion des prospects* : suivi des contacts acquéreurs/locataires affectés à ses missions (`app/agent-prospects.tsx`).
  5. *Annuaire & Recherche d'agents* : consultation et mise en relation (`app/agents.tsx`, `app/find-agent.tsx`).
  6. *Révocation & Lecture seule* : conservation de l'historique d'activité même en cas de mandat révoqué (`AG-06`).
- **Fonctionnalités identifiables :**
  - Plafond de délégations simultanées (ex: 5 places par bailleur).
  - Gestion des cas de suspension de badge de l'agent ou d'expiration de l'abonnement du bailleur (`EDGE-07`, `EDGE-08`).
- **Fichiers frontend :**
  - `app/agent-space.tsx`, `app/agent-portfolio.tsx`, `app/agent-prospects.tsx`
  - `app/agent-invitation.tsx`, `app/agent-invite.tsx`, `app/agents.tsx`, `app/find-agent.tsx`
  - `lib/api/agents.ts`
- **Opérations de données nécessaires :**
  - Envoi, acceptation, refus et révocation d'invitations de délégation d'annonces.
  - Lecture des biens sous mandat actif pour un agent connecté.
  - Recherche et filtrage dans l'annuaire public des agents par ville/quartier.

---

### Module 10 : BO — Boost & Sponsoring

- **Rôle métier :** Permettre aux utilisateurs, créateurs et professionnels d'amplifier la visibilité d'un post Square ou d'un Reel via des campagnes publicitaires payantes ciblées.
- **Sous-modules :**
  1. *Sélection du contenu* : choix parmi ses publications ou reels (les annonces ne se boostent pas directement).
  2. *Ciblage & Objectifs* : définition de l'audience (rayon géographique, quartier, tranches d'âge, centres d'intérêt) et de l'objectif (vues, abonnements, messages).
  3. *Budget & Calendrier* : fixation du montant (XAF/XOF), durée (3, 7, 14, 30 jours), calcul de la portée estimée en temps réel.
  4. *Règlement de la campagne* : récapitulatif avec frais opérateur, paiement Mobile Money ou utilisation du Crédit BANGO.
  5. *Suivi de campagne* : tableau de bord de performance (impressions, portée quotidienne, clics, profil des spectateurs).
  6. *Interruption & Remboursement* : gel en cas de signalement modération, recrédit automatique au prorata en Crédit BANGO.
- **Fonctionnalités identifiables :**
  - Estimation dynamique de la portée basée sur le budget (`catalog.estimateReach`).
  - Conversion automatique des devises pour les campagnes inter-pays.
- **Fichiers frontend :**
  - `app/boost.tsx`, `app/boost-target.tsx`, `app/boost-budget.tsx`, `app/boost-review.tsx`, `app/boost-failed.tsx`
  - `app/campaign.tsx`, `app/credit.tsx`, `app/earnings.tsx`
  - `lib/api/billing.ts`, `lib/api/catalog.ts`
- **Opérations de données nécessaires :**
  - Simulation de portée et devis de campagne.
  - Création, activation et arrêt d'une campagne sponsorisée.
  - Consultation des statistiques de diffusion d'une campagne.
  - Consultation et consommation du solde de Crédit BANGO.

---

### Module 11 : P / AB — Abonnements & Badges de Confiance

- **Rôle métier :** Administrer le catalogue d'abonnements périodiques (Badge Bailleur, Badge Agent), gérer leur cycle de vie, les alertes d'échéance et les renouvellements.
- **Sous-modules :**
  1. *Catalogue des offres* : présentation des tarifs (mensuel / annuel) et des privilèges associés.
  2. *Souscription initiale* : choix de la formule, devis certifié, paiement Mobile Money.
  3. *Notifications de renouvellement* : alertes automatiques à J-7, J-3 et J-1 avant l'échéance.
  4. *Gestion des échecs de paiement* : déclenchement de la période de grâce de 7 jours avec avertissement (`AB-05`, `AB-06`).
  5. *Expiration & Dépublication* : dépublication automatique des annonces et gel des mandats dès expiration finale (`P-03`).
  6. *Migration de formule* : surclassement (upgrade) ou changement de cadence avec calcul de prorata.
  7. *Résiliation* : parcours de rétention avec mise en avant des gains générés par le badge, confirmation et prise d'effet à terme.
- **Fonctionnalités identifiables :**
  - Historique des badges attribués et expirés.
  - Calcul du prorata temporis lors du changement de plan en cours de période.
- **Fichiers frontend :**
  - `app/subscriptions.tsx`, `app/badges.tsx`, `app/badge-expired.tsx`
  - `app/plan-change.tsx`, `app/plan-changed.tsx`, `app/cancel-subscription.tsx`
  - `app/renew-reminder.tsx`, `app/renewal-done.tsx`, `app/renewal-failed.tsx`
  - `components/billing/` (PlanCard, RenewalSheet, RetentionModal)
  - `lib/api/billing.ts`, `lib/api/renewals.ts`, `lib/api/catalog.ts`
- **Opérations de données nécessaires :**
  - Lecture du statut d'abonnement actif et de l'historique des transactions d'adhésion.
  - Devis de souscription et devis de renouvellement certifiés serveur.
  - Modification de plan avec calcul de compensation prorata.
  - Résiliation programmée de la reconduction tacite.

---

### Module 12 : PG — Pages Professionnelles

- **Rôle métier :** Permettre aux entreprises, agences immobilières et artisans d'exister en tant qu'entité morale distincte des comptes personnels, avec multi-administration et certification officielle.
- **Sous-modules :**
  1. *Création de Page Pro* : dénomination, secteur (agence, commerce, artisan), description, visuels.
  2. *Bascule d'identité* : passer en un tap du profil personnel à l'une de ses pages professionnelles (`PG-04`).
  3. *Gestion des rôles* : attribution des statuts Propriétaire (finance), Éditeur (contenu), Modérateur (échanges) (`PG-03`).
  4. *Dossier de certification d'entreprise* : envoi du RCCM, identifiant fiscal, pièce du gérant, paiement des frais d'examen (`PG-05`).
  5. *Suivi d'instruction de certification* : consultation de l'avancement, traitement des motifs de refus (`PG-07`), 2 corrections incluses.
  6. *Boîte de réception dédiée* : messagerie cloisonnée pour les échanges professionnels de la page (`MS-19`).
- **Fonctionnalités identifiables :**
  - Distinction visuelle claire (avatar carré arrondi vs profil rond).
  - Gestion de la mise en pause de la certification si le badge du créateur expire (`EDGE-24`).
- **Fichiers frontend :**
  - `app/page.tsx`, `app/my-pages.tsx`, `app/page-new.tsx`, `app/page-switch.tsx`
  - `app/page-roles.tsx`, `app/page-certify.tsx`, `app/page-inbox.tsx`
  - `lib/api/pages.ts`
- **Opérations de données nécessaires :**
  - CRUD d'une entité Page Professionnelle.
  - Gestion des membres de l'équipe et de leurs rôles RBAC.
  - Soumission et suivi du dossier de vérification d'entreprise (KYC Entreprise).
  - Bascule de contexte de session active.

---

### Module 13 : MS — Messagerie Privée

- **Rôle métier :** Fournir une messagerie instantanée fluide permettant aux acheteurs, locataires, bailleurs et agents d'échanger en privé avec ancrage contextuel sur les biens.
- **Sous-modules :**
  1. *Boîte de réception générale* : liste des fils de discussion ordonnés par date, indicateur de non-lu, filtres (tous, immo, directs).
  2. *Discussion contextuelle immobilière* : bandeau supérieur fixe rappelant le bien, le loyer et le créneau de visite convenu (`MS-02`).
  3. *Envoi multimédia & Notes vocales* : enregistrement audio avec durée, prévisualisation et lecture, envoi d'images.
  4. *Discussions de groupe* : création d'un groupe, gestion des membres, rôles d'administrateur, départ du dernier admin (`MS-14` à `MS-18`).
  5. *Demandes de message* : boîte d'attente pour les personnes hors carnet ou sans lien préalable, avec acceptation/refus.
  6. *Indicateurs en temps réel* : saisie en cours (`typing`), accusés de distribution et de lecture.
  7. *Sécurité & Blocage* : option de bloquer ou signaler l'interlocuteur directement depuis le fil, gestion de l'affichage muet des comptes supprimés (`EDGE-15`).
  8. *Rattachement Story* : fil initié depuis une réponse privée à une Story, affichant la vignette de la Story en haut du chat.
- **Fonctionnalités identifiables :**
  - File d'attente des messages non envoyés en cas de perte de connectivité avec tentative de rejeu (`MS-08`, `SY-03`).
  - Consultation en mode "lecture seule" pour les agents dont le mandat a été révoqué (`MS-20`).
- **Fichiers frontend :**
  - `app/(tabs)/messages.tsx`
  - `app/thread.tsx`, `app/thread-media.tsx`, `app/message-new.tsx`, `app/message-requests.tsx`, `app/message-group.tsx`, `app/group-info.tsx`
  - `components/messages/` (ThreadItem, MessageBubble, VoiceRecorder, ContextCard, InputBar)
  - `lib/api/messages.ts`
- **Opérations de données nécessaires :**
  - Liste paginée des fils de conversation de l'utilisateur.
  - Récupération paginée de l'historique des messages d'un fil.
  - Envoi de messages texte, vocaux et médias avec clé d'idempotence.
  - Marquage des messages comme lus / accusés de réception.
  - Gestion des membres de groupes de discussion.

---

### Module 14 : RC — Recherche & Découverte

- **Rôle métier :** Moteur de recherche transversal permettant de retrouver des comptes, des publications, des hashtags, des annonces immobilières et des agents.
- **Sous-modules :**
  1. *Accueil Recherche* : recherches récentes mémorisées, suggestions contextuelles locales.
  2. *Onglet Comptes* : recherche d'utilisateurs par nom ou handle avec indication des badges.
  3. *Onglet Publications* : recherche plein texte dans les légendes du Square.
  4. *Onglet Hashtags* : recherche et exploration des mots-clés populaires avec volume de posts.
  5. *Onglet Biens Immo* : recherche de logements avec mise en avant prioritaire des biens sponsorisés (boostés).
  6. *Recherche d'Agents* : filtre spécialisé pour localiser les agents immobiliers opérant sur un quartier précis.
  7. *Tendances* : top des hashtags et musiques les plus utilisés dans la ville.
- **Fonctionnalités identifiables :**
  - Effacement de l'historique de recherche en un tap (`RC-08`).
  - Page dédiée à un hashtag (`app/hashtag.tsx`) affichant l'ensemble des contenus associés.
- **Fichiers frontend :**
  - `app/search.tsx`, `app/trends.tsx`, `app/hashtag.tsx`, `app/find-agent.tsx`
  - `components/search/` (SearchBar, SearchTabs, TrendingList, HistoryList)
  - `lib/api/search.ts`
- **Opérations de données nécessaires :**
  - Requêtes de recherche textuelle multi-domaines (utilisateurs, publications, annonces, hashtags).
  - Consultation des hashtags et sons tendances du moment.
  - Enregistrement et purge de l'historique des requêtes de recherche.

---

### Module 15 : PR — Profil Utilisateur

- **Rôle métier :** Représentation de l'identité numérique de l'utilisateur ou d'un tiers, de ses publications, de ses badges d'autorité et de son réseau social.
- **Sous-modules :**
  1. *Mon Profil* : affichage de la photo, du nom, du handle, des badges actifs, de la biographie, des centres d'intérêt et des onglets de contenu (Publications, Reels, Biens gérés si bailleur, Sélections).
  2. *Édition du profil* : modification du nom, bio, ville, quartier, genre, date de naissance, photo d'avatar.
  3. *Profil Tiers* : consultation du profil d'un autre utilisateur avec affichage contextuel de son statut de confiance, bouton Suivre / Ne plus suivre.
  4. *Comptes privés & Demandes d'abonnement* : gestion des demandes d'abonnement reçues et émises.
  5. *Listes de relations* : abonnés et abonnements avec filtres de recherche.
  6. *Enregistrements* : consultation privée de ses publications et biens favoris sauvegardés.
  7. *Partage par QR Code* : génération de son QR Code de contact stylisé et scanner de QR Code intégré pour ajout rapide (`PR-16`, `PR-17`).
- **Fonctionnalités identifiables :**
  - Pédagogie des badges : feuille d'information expliquant la signification de chaque couleur d'anneau (`PR-14`).
  - Verrouillage automatique de la section "Créer une annonce" pour les utilisateurs sans badge Bailleur valide (`PR-06`).
- **Fichiers frontend :**
  - `app/(tabs)/profile.tsx`
  - `app/profile-edit.tsx`, `app/followers.tsx`, `app/qr.tsx`, `app/qr-scan.tsx`
  - `app/u/[handle].tsx`
  - `components/account/` (ProfileHeader, BadgePill, TabNavigator, QrCard)
  - `lib/api/profile.ts`, `lib/api/friends.ts`
- **Opérations de données nécessaires :**
  - Consultation des données d'un profil par ID ou par handle.
  - Mise à jour des champs d'identité et de localisation.
  - Téléversement d'un nouvel avatar.
  - Gestion des relations de suivi (follow, unfollow, accept/reject follow request).
  - Résolution d'un lien ou d'une payload de QR Code.

---

### Module 16 : ST — Paramètres & Sécurité du Compte

- **Rôle métier :** Espace central de configuration de l'application, de gestion de la sécurité, de la double authentification et des options de confidentialité.
- **Sous-modules :**
  1. *Compte & Sécurité* : changement d'adresse e-mail avec validation par code envoyé à la nouvelle adresse et alerte sur l'ancienne (`ST-05`, `ST-06`).
  2. *Mot de passe* : modification authentifiée du mot de passe avec contrôle de force.
  3. *Double Authentification (2FA)* : activation par SMS ou application d'authentification (TOTP), génération et régénération des 8 codes de secours à usage unique (`ST-09`, `ST-10`).
  4. *Sessions actives* : inventaire des appareils connectés avec géolocalisation IP, déconnexion à distance ciblée ou générale (`ST-11`, `ST-12`).
  5. *Bouton d'urgence "Sécuriser le compte"* : déconnexion immédiate de tous les autres appareils, réinitialisation imposée du mot de passe (`ST-13`).
  6. *Confidentialité* : contrôle de visibilité du profil, masquage du genre, refus du ciblage publicitaire.
  7. *Préférences générales* : langue applicative, paramètres de notifications push, mode d'économie des données et stockage cache.
- **Fonctionnalités identifiables :**
  - Liste des codes de secours avec marquage individuel des codes déjà utilisés.
  - Mise en pause temporaire de son compte sans suppression de données (`app/account.tsx`).
- **Fichiers frontend :**
  - `app/settings.tsx`, `app/settings-account.tsx`, `app/settings-password.tsx`, `app/settings-2fa.tsx`
  - `app/settings-privacy.tsx`, `app/settings-notifications.tsx`, `app/settings-language.tsx`, `app/settings-data.tsx`, `app/settings-ads.tsx`, `app/settings-accessibility.tsx`
  - `app/change-email.tsx`, `app/backup-codes.tsx`, `app/sessions.tsx`, `app/security-alert.tsx`, `app/login-alert.tsx`
  - `lib/api/security.ts`, `lib/api/settings.ts`
- **Opérations de données nécessaires :**
  - Procédure en deux étapes pour le changement d'adresse email.
  - Mise à jour sécurisée du mot de passe.
  - Configuration TOTP / 2FA et vérification de code.
  - Gestion des tokens de session d'appareils et révocation.
  - Mise à jour des réglages de confidentialité.

---

### Module 17 : PAY — Paiement Mobile Money & Portefeuille

- **Rôle métier :** Moteur de règlement des transactions BANGO via les réseaux de Mobile Money locaux (Orange Money, MTN MoMo, Wave, Moov) et suivi comptable.
- **Sous-modules :**
  1. *Tunnel de paiement* : sélection du pays de paiement, choix de l'opérateur, saisie du numéro payeur avec détection de format.
  2. *Émission USSD Push* : envoi de la requête de débit sur le mobile de l'utilisateur, écran d'attente de saisie du code PIN sur son clavier opérateur (`PAY-07`).
  3. *Suivi asynchrone du statut* : interrogation régulière (polling 120s), gestion des pannes réseau avec reprise automatique de la vérification (`PAY-13`, `PAY-14`).
  4. *Résultat de la transaction* : écrans dédiés selon le verdict de l'opérateur (Succès, Refus, Délai dépassé, Solde insuffisant).
  5. *Idempotence & Protection anti-doublon* : détection et blocage des doubles débits accidentels (`PAY-15`, `EDGE-17`).
  6. *Portefeuille Crédit BANGO* : consultation de son solde de crédits (issus d'annulations ou de compensations), paiement partiel ou total en crédits (`PAY-16`, `PAY-17`).
  7. *Moyens de paiement enregistrés* : mémorisation de son numéro de prédilection pour règlements en 1 clic.
  8. *Historique & Reçus* : relevé complet des transactions avec détail et reçu téléchargeable certifié (`PAY-21` à `PAY-23`).
- **Fonctionnalités identifiables :**
  - Conversion monétaire automatique selon le pays choisi pour le paiement (`PAY-03`).
  - Déclaration de réclamation pour double débit constaté (`app/double-charge.tsx`).
- **Fichiers frontend :**
  - `app/payment.tsx`, `app/payment-method.tsx`, `app/payment-number.tsx`, `app/payment-pending.tsx`
  - `app/payment-result.tsx`, `app/payment-unknown.tsx`, `app/payment-duplicate.tsx`, `app/payment-credit.tsx`
  - `app/transactions.tsx`, `app/transaction.tsx`, `app/double-charge.tsx`
  - `components/payment/` (OperatorPicker, NumberInput, StatusPoller, ReceiptCard)
  - `lib/api/payments.ts`
- **Opérations de données nécessaires :**
  - Établissement d'un devis certifié serveur avant tout débit.
  - Initiation du paiement USSD avec fourniture d'une clé d'idempotence unique.
  - Polling du statut en temps réel d'un paiement en attente.
  - Consultation de l'historique paginé des transactions du compte.
  - Génération de reçu téléchargeable / vérifiable.

---

### Module 18 : SUP — Support, Signalement & Modération

- **Rôle métier :** Fournir l'assistance aux usagers, assurer la sécurité de la communauté via le traitement des signalements et instruire les recours contre les sanctions.
- **Sous-modules :**
  1. *Centre d'aide & FAQ* : consultation d'articles d'assistance thématiques, moteur de recherche plein texte dans la base de connaissances, vote d'utilité (`SUP-01` à `SUP-03`).
  2. *Tickets de support* : formulaire de contact avec sélection de catégorie, rédaction, ajout de pièces jointes, suivi des tickets et notation du support (`SUP-04` à `SUP-09`).
  3. *Signalement de contenu & d'annonces* : qualification précise du grief (fausse annonce, escroquerie, violence, etc.), fourniture de preuves, anonymat garanti du signalant (`SUP-10` à `SUP-12`).
  4. *Suivi de mes signalements* : historique des signalements émis et statut de traitement par les modérateurs (`SUP-13`).
  5. *Notifications de modération reçues* : avertissements, notification de retrait de contenu, notification de restriction ou suspension de compte (`SUP-19` à `SUP-21`).
  6. *Procédure de Recours (Appels)* : formulaire formel de contestation d'une décision de modération avec examen par un modérateur humain (`SUP-22` à `SUP-24`, `app/appeal.tsx`).
- **Fonctionnalités identifiables :**
  - Alerte automatique transmise aux personnes ayant été en contact avec une annonce reconnue frauduleuse (`SUP-18`).
  - Référence unique de signalement (`SIG-XXXX`) communicable au support.
- **Fichiers frontend :**
  - `app/support.tsx`, `app/support-new.tsx`, `app/tickets.tsx`, `app/help-article.tsx`
  - `app/report.tsx`, `app/report-sent.tsx`, `app/my-reports.tsx`
  - `app/moderation-notice.tsx`, `app/content-deleted.tsx`, `app/appeal.tsx`
  - `components/moderation/` (ReportModal, TicketForm, StatusBadge, AppealSheet)
  - `lib/api/help.ts`, `lib/api/moderation.ts`
- **Opérations de données nécessaires :**
  - Consultation des articles d'aide et de la FAQ.
  - Création, consultation et réponse sur un ticket de support client.
  - Dépôt d'un signalement horodaté et anonyme sur un contenu ou utilisateur.
  - Dépôt et suivi d'un recours contre une sanction de modération.

---

### Module 19 : SY — États Système & Résilience

- **Rôle métier :** Composants transverses gérant la robustesse technique : déconnexion réseau, tolérance aux pannes de service, reprise de téléversement et intégrité de l'app.
- **Sous-modules :**
  1. *Mode Hors-Ligne & Cache* : détection de rupture réseau, affichage de bannières indiquant la fraîcheur des données mises en cache, désactivation des gestes d'écriture.
  2. *File d'attente d'actions déconnectées* : mémorisation des likes, sauvegardes et messages en attente, vidage automatique et réémission atomique au retour en ligne (`SY-03`, `SY-04`).
  3. *Reprise de téléversement par morceaux (Chunking / Tus)* : reprise automatique des transferts de gros fichiers vidéo interrompus à l'offset exact sans repartir de zéro (`SY-07`).
  4. *Gestion des erreurs serveur (5xx)* : écran d'erreur serveur avec numéro d'incident unique et heure GMT pour faciliter le diagnostic (`SY-06`).
  5. *Gestion des permissions matérielles* : écrans explicatifs et guidage vers les réglages iOS/Android en cas de refus d'accès caméra, micro, galerie, localisation ou notifications.
  6. *Squelettes & États vides* : placeholders visuels pendant le chargement des listes et écrans d'état vide pédagogiques.
  7. *Cycle de maintenance* : écran de blocage pour maintenance planifiée (`SY-22`) et obligation de mise à jour de version (`SY-21`).
- **Fonctionnalités identifiables :**
  - Invites périodiques à noter l'application sur le Play Store / App Store (`SY-23`).
  - Détection de mémoire saturée sur le terminal (`SY-24`).
- **Fichiers frontend :**
  - `app/maintenance.tsx`, `app/update-required.tsx`
  - `components/ui/` (Skeleton, EmptyState, OfflineBanner, ToastContainer)
  - `lib/api/sync.ts`, `lib/api/incidents.ts`, `lib/api/storage.ts`
- **Opérations de données nécessaires :**
  - Envoi en lot d'actions différées (`sync.flush`).
  - Requête d'offset de téléversement (`HEAD /uploads/{id}`) et envoi par tranches (`PATCH`).
  - Ping d'intégrité et contrôle de l'état des services de la plateforme.

---

### Module 20 : EDGE — Cas Limites & Cohérence Inter-Comptes

- **Rôle métier :** Scénarios critiques où l'action d'un utilisateur impacte directement les droits ou la visibilité d'un autre utilisateur sur la plateforme.
- **Sous-modules :**
  1. *Révocation de mandat* : passage en lecture seule immédiate pour l'agent révoqué, réattribution des annonces au bailleur (`EDGE-02`, `EDGE-12`).
  2. *Bien supprimé ou loué* : gestion de l'affichage pour le prospect ayant déjà réservé une visite sur ce bien (`EDGE-03`, `EDGE-11`).
  3. *Gel de campagne publicitaire* : blocage d'un contenu boosté qui fait l'objet d'un signalement de fraude, crédit sécurisé (`EDGE-04`).
  4. *Perte de badge de confiance* : dépublication des annonces si le bailleur perd son badge, gel des capacités de l'agent si sa carte expire (`EDGE-07`, `EDGE-08`).
  5. *Liens de partage orphelins* : redirection gracieuse lorsqu'un lien `bango.app/r/...` partagé pointe vers un contenu supprimé (`EDGE-13`, `EDGE-14`).
  6. *Blocage mutuel étanche* : interdiction d'accès bilatérale totale dans les commentaires, les messages, les visites et les suggestions (`EDGE-22`, `EDGE-23`).
  7. *Régularisation de double prélèvement* : traitement d'un cas où l'opérateur a débité deux fois, génération immédiate d'un Crédit BANGO équivalent (`EDGE-18`).
- **Fonctionnalités identifiables :**
  - Conservation des preuves écrites antérieures dans les conversations même en cas de suppression de compte ou de litige.
- **Fichiers frontend :**
  - `app/link-dead.tsx`, `app/blocked.tsx`
  - Traitements transverses dans `lib/api/access.ts`, `lib/api/messages.ts`, `lib/api/landlord.ts`, `lib/api/agents.ts`
- **Opérations de données nécessaires :**
  - Résolution universelle de liens profonds avec gestion des statuts de contenus désactivés.
  - Révocation atomique de droits et cascading des statuts de biens associés.
  - Détection automatique et compensation des anomalies de paiement.

---

### Module 21 : NOTIF — Notifications Push & In-App

- **Rôle métier :** Centre de notifications de l'application regroupant les alertes transactionnelles, immobilières, sociales et de sécurité.
- **Sous-modules :**
  1. *Fil de notifications* : regroupement temporel ("Aujourd'hui", "Cette semaine"), filtrage par catégorie (Toutes, Immo, Social, Compte).
  2. *Marquage de lecture* : marquer une notification comme lue, marquer tout comme lu, gestion du compteur de pastille (badge).
  3. *Enregistrement de device* : enregistrement du push token APNs / FCM.
- **Fonctionnalités identifiables :**
  - Redirection contextuelle vers la ressource ciblée au clic sur la notification.
- **Fichiers frontend :**
  - `app/notifications-feed.tsx`
  - `lib/api/notificationsFeed.ts`, `lib/api/notifications.ts`
- **Opérations de données nécessaires :**
  - Récupération paginée des notifications de l'utilisateur avec filtrage.
  - Mise à jour de l'état de lecture (`markAllRead`, `markRead`).
  - Enregistrement du token push sur le profil de l'utilisateur.

---

## 3. Synthèse des Besoins d'Opérations de Données par Module

| Module Frontend | Opérations de Données Requises |
| :--- | :--- |
| **SP (Lancement)** | Lecture des préférences de langue, contrôle de version app. |
| **AU (Auth / Onboard)** | Création compte, connexion Passport, validation 2FA, SMS OTP, vérification pseudo unique, mise à jour étapes onboarding. |
| **LG (Légal / RGPD)** | Consultation CGU versionnées, enregistrement acceptation CGU, demande et téléchargement export RGPD, programmation suppression compte. |
| **SQ (Square)** | Feed paginé par quartier, création post, commentaires arborescents, likes, partages, archivage, masquage. |
| **RL (Reels)** | Feed vidéo vertical, likes/dislikes mutuels, commentaires vidéo, catalogue sons, gestion pas intéressé. |
| **CR (Création)** | Upload médias (photos, vidéos, audio), sauvegarde brouillons multi-segments, publication post/reel/story/annonce. |
| **IM (L'Immo)** | Recherche géo-spatiale, filtres multi-critères, consultation créneaux, réservation créneau, favoris, alertes. |
| **BL (Bailleur)** | Gestion parc de biens, statuts d'annonces, calendrier de visite, métriques statistiques des annonces, délégations. |
| **AG (Agent)** | Mandats confiés, acceptation/refus invitations, annuaire public des agents, suivi missions de visite. |
| **BO (Boost)** | Devis sponsoring, calcul portée estimée, activation campagne, suivi métriques impressions/clics, solde Crédit BANGO. |
| **P / AB (Abonnements)** | Catalogue des tarifs badges, souscription, renouvellement manuel assisté, gestion des relances et résiliation. |
| **PG (Pages Pro)** | CRUD page pro, gestion des rôles membres, dépôt et suivi dossier certification RCCM/fiscal. |
| **MS (Messagerie)** | Fils de discussion, envoi/réception messages texte/vocaux/médias, gestion groupes, blocage, contexte annonce immo. |
| **RC (Recherche)** | Recherche transversale comptes/posts/biens/hashtags, tendances locales, historique de recherche. |
| **PR (Profil)** | Consultation profil public/privé, mise à jour identité, téléversement avatar, abonnements/abonnés, QR code. |
| **ST (Paramètres)** | Changement email, mise à jour mot de passe, 2FA/TOTP/codes secours, sessions actives, verrouillage d'urgence. |
| **PAY (Paiement)** | Devis certifié, initiation push USSD Mobile Money, polling statut temps réel, reçu vérifiable, litiges. |
| **SUP (Support / Mod)** | Consultation base connaissances, tickets support, signalement anonyme de contenu/annonce, recours contre sanction. |
| **SY (Système)** | Dépôt par tranches vidéo (Tus), file d'attente hors-ligne, journalisation des incidents 5xx. |
| **EDGE (Cas Limites)** | Résolution de liens profonds avec statuts de repli, révocations en cascade, régularisation de doubles débits. |
| **NOTIF (Notifications)** | Liste paginée des alertes, acquittement de lecture, enregistrement push token. |
