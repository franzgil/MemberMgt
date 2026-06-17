<?php
/**
 * Gemeinsames Formular für Anlegen/Bearbeiten.
 * Erwartet: $m (array, ggf. leer), $action (string), $submit (string),
 *           $csrf (string), $status (array)
 * @var array $m @var string $action @var string $submit @var array $status
 */
$v = static function (string $key, $default = '') use ($m) {
    return e(old($key, $m[$key] ?? $default));
};
?>
<form class="form" method="post" action="<?= e($action) ?>">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">

    <fieldset>
        <legend>Person</legend>
        <div class="row">
            <label>Vorname *<input name="vorname" value="<?= $v('vorname') ?>" required></label>
            <label>Nachname *<input name="nachname" value="<?= $v('nachname') ?>" required></label>
        </div>
        <div class="row">
            <label>E-Mail<input type="email" name="email" value="<?= $v('email') ?>"></label>
            <label>Telefon<input name="telefon" value="<?= $v('telefon') ?>"></label>
        </div>
        <div class="row">
            <label>Geburtsdatum<input type="date" name="geburtsdatum" value="<?= $v('geburtsdatum') ?>"></label>
            <label>Geburtsort<input name="geburtsort" value="<?= $v('geburtsort') ?>"></label>
            <label>Geburtsland<input name="geburtsland" value="<?= $v('geburtsland') ?>"></label>
        </div>
        <div class="row">
            <label>Matricule (LU)<input name="matricule" value="<?= $v('matricule') ?>" maxlength="13"></label>
        </div>
    </fieldset>

    <fieldset>
        <legend>Adresse</legend>
        <div class="row">
            <label>Hausnummer<input name="hausnummer" value="<?= $v('hausnummer') ?>"></label>
            <label class="grow">Straße<input name="strasse" value="<?= $v('strasse') ?>"></label>
        </div>
        <div class="row">
            <label>PLZ<input name="plz" value="<?= $v('plz') ?>"></label>
            <label>Ort<input name="ort" value="<?= $v('ort') ?>"></label>
            <label>Land<input name="land" value="<?= $v('land', 'Luxembourg') ?>"></label>
        </div>
    </fieldset>

    <fieldset>
        <legend>Mitgliedschaft & Forum</legend>
        <div class="row">
            <label>Mitgliedsnummer<input name="mitgliedsnummer" value="<?= $v('mitgliedsnummer') ?>" maxlength="6"></label>
            <label>Forenname (WoltLab)<input name="forum_name" value="<?= $v('forum_name') ?>"></label>
            <label>WoltLab-User-ID<input type="number" name="wcf_user_id" value="<?= $v('wcf_user_id') ?>"></label>
        </div>
        <div class="row">
            <label>Status
                <select name="status">
                    <?php $cur = old('status', $m['status'] ?? 'antrag'); ?>
                    <?php foreach ($status as $st): ?>
                        <option value="<?= e($st) ?>" <?= $cur === $st ? 'selected' : '' ?>><?= e(ucfirst($st)) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Antragsart
                <?php $aart = old('antragsart', $m['antragsart'] ?? ''); ?>
                <select name="antragsart">
                    <option value="">–</option>
                    <option value="online" <?= $aart === 'online' ? 'selected' : '' ?>>Online</option>
                    <option value="papier" <?= $aart === 'papier' ? 'selected' : '' ?>>Papier</option>
                </select>
            </label>
            <label>Antragsdatum<input type="date" name="antragsdatum" value="<?= $v('antragsdatum') ?>"></label>
        </div>
        <div class="row">
            <label class="check">
                <input type="checkbox" name="karte_ausgestellt" value="1"
                    <?= old('karte_ausgestellt', $m['karte_ausgestellt'] ?? 0) ? 'checked' : '' ?>>
                Mitgliedskarte ausgehändigt
            </label>
        </div>
    </fieldset>

    <fieldset>
        <legend>Notizen</legend>
        <label>Bemerkung<textarea name="bemerkung" rows="3"><?= $v('bemerkung') ?></textarea></label>
    </fieldset>

    <div class="form-actions">
        <button type="submit" class="btn-primary"><?= e($submit) ?></button>
        <a class="btn" href="<?= url('/mitglieder') ?>">Abbrechen</a>
    </div>
</form>
