-- 0118 — BUREAUX ONBOARDÉS INVISIBLES : réactivation.
--
-- /franchisee/onboard-office créait le bureau avec active=0 (« non validé »),
-- alors que active=0 signifie SUPPRIMÉ pour toutes les lectures
-- (GET ws-offices ne sert que active=1). Chaque bureau onboardé disparaissait
-- donc des listes au premier rafraîchissement. Le statut « pending » suffit à
-- fermer la livraison ; on rend visibles les bureaux en attente encore inactifs.
UPDATE ws_offices SET active = 1 WHERE active = 0 AND status = 'pending';
