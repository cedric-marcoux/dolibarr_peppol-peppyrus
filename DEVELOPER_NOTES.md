# Notes de développement - Module Peppol Peppyrus

## RÈGLES CRITIQUES À RESPECTER

### 1. Nom du module et dossier
- **Nom du dossier** : `peppolpeppyrus` (SANS tiret)
- **Classe du module** : `modPeppolpeppyrus`
- **Fichier** : `core/modules/modPeppolpeppyrus.class.php`
- **Classe actions** : `ActionsPeppolpeppyrus`
- **Fichier actions** : `class/actions_peppolpeppyrus.class.php`

> ⚠️ Dolibarr cherche les hooks dans `/$module/class/actions_$module.class.php`
> Le nom du dossier DOIT correspondre au nom du module en minuscules.

### 2. Références dans le code
Toujours utiliser :
- `$conf->peppolpeppyrus->enabled`
- `$user->rights->peppolpeppyrus->...`
- `restrictedArea($user, 'peppolpeppyrus')`
- `peppol@peppolpeppyrus` (pour les fichiers de langue)
- `/peppolpeppyrus/...` (pour les chemins)

❌ NE JAMAIS utiliser :
- `peppol-peppyrus` (avec tiret)
- `$conf->peppol->`
- `peppol@peppol`

### 3. Fichiers de langue
Les clés du module doivent être :
- `ModulePeppolpeppyrusName`
- `ModulePeppolpeppyrusDesc`

### 4. Création du ZIP pour distribution
```bash
cd /data/docker/dolibarr/data/custom
zip -r module_peppolpeppyrus-X.Y.Z.zip peppolpeppyrus -x "peppolpeppyrus/.git/*"
```

> ⚠️ Le nom du fichier ZIP doit correspondre au nom du dossier !
> Dolibarr vérifie que le ZIP contient un dossier nommé comme le module.

### 5. Structure des fichiers
```
peppolpeppyrus/
├── class/
│   ├── actions_peppolpeppyrus.class.php  ← Hook class
│   ├── peppol.class.php
│   └── ...
├── core/
│   └── modules/
│       └── modPeppolpeppyrus.class.php   ← Module descriptor
├── langs/
│   ├── en_US/peppol.lang
│   ├── fr_FR/peppol.lang
│   └── ...
├── admin/
│   └── setup.php
├── search.php
└── ...
```

### 6. GitHub Release
- Repository : `cedric-marcoux/dolibarr_peppol-peppyrus`
- Fichier ZIP : `module_peppolpeppyrus-2.0.0.zip`
- Tag : `v2.0.0`

### 7. Installation
Le module doit être extrait dans :
- `/htdocs/custom/peppolpeppyrus/` (installation manuelle)
- Ou via "Déployer un module externe" dans Dolibarr

---

## Historique des problèmes résolus

### Problème 1 : Bouton PeppolFinder non affiché
**Cause** : Le dossier s'appelait `peppol-peppyrus` mais la classe `ActionsPeppolpeppyrus`
**Solution** : Renommer le dossier en `peppolpeppyrus`

### Problème 2 : Access Denied sur search.php
**Cause** : `restrictedArea($user, 'peppol')` au lieu de `peppolpeppyrus`
**Solution** : Corriger toutes les références de permissions

### Problème 3 : Extrafield peppol_id non visible
**Cause** : `enabled='$conf->peppol->enabled'` dans la DB
**Solution** : Mettre à jour en `$conf->peppolpeppyrus->enabled`

### Problème 4 : Module non chargé (Class not found)
**Cause** : Fichier contenait `class modPeppol` au lieu de `class modPeppolpeppyrus`
**Solution** : Corriger le nom de la classe

### Problème 5 : ZIP refusé par Dolibarr
**Cause** : Nom du ZIP `module_peppol-peppyrus-2.0.0.zip` mais dossier `peppolpeppyrus`
**Solution** : Nommer le ZIP `module_peppolpeppyrus-2.0.0.zip`
