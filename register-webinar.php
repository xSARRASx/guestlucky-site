<?php
/**
 * GuestLucky — Notification d'inscription au webinaire
 *
 * L'inscription réelle sur WebinarJam est faite par le NAVIGATEUR du visiteur,
 * qui est redirigé vers l'URL 1-click WebinarJam (voir webinaire.html). Une URL
 * 1-click ne crée une inscription que lorsqu'un vrai navigateur l'ouvre — un
 * appel curl côté serveur ne l'enregistre pas.
 *
 * Ce script se contente donc d'envoyer une notification email à l'admin pour
 * chaque inscription. Il est appelé en arrière-plan (navigator.sendBeacon).
 */

const NOTIFY_EMAIL = 'contact@guestlucky.com'; // mettre "" pour désactiver

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$firstname = trim($_POST['firstname'] ?? '');
$lastname  = trim($_POST['lastname']  ?? '');
$email     = trim($_POST['email']     ?? '');
$phone     = trim($_POST['phone']     ?? '');

if (!$firstname || !$lastname || !$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Champs manquants ou invalides']);
    exit;
}

if (NOTIFY_EMAIL) {
    $subject = "[Webinaire GuestLucky] Inscription : $firstname $lastname";
    $body  = "Nouvelle inscription au webinaire :\n\n";
    $body .= "Prénom : $firstname\n";
    $body .= "Nom : $lastname\n";
    $body .= "Email : $email\n";
    if ($phone) $body .= "Téléphone : $phone\n";
    $body .= "Date : " . date('Y-m-d H:i:s') . "\n";
    $body .= "Source : " . (trim($_POST['source'] ?? '') ?: 'webinaire.html') . "\n";
    @mail(NOTIFY_EMAIL, $subject, $body, "From: noreply@guestlucky.com\r\n");
}

echo json_encode(['ok' => true]);
