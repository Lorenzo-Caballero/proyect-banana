<?php
/**
 * cripto.php — Guardar una credencial ajena sin dejarla en claro.
 *
 * PARA QUÉ EXISTE. Desde el 22/09/2026 el cliente carga en su CRM la
 * contraseña de aplicación de SU casilla de mail, para que el colector lea los
 * avisos de su banco y le acredite las transferencias solo. Esa credencial no
 * es nuestra, y guardarla como texto en una columna era el camino fácil.
 *
 * CUÁNTO COMPRA ESTO, SIN VENDERLO DE MÁS: la llave vive en el mismo servidor
 * que la base. O sea que protege contra un backup filtrado, un volcado de la
 * base o una inyección SQL — NO contra alguien que ya entró al VPS, que puede
 * leer la llave igual que lee la base. Es el mínimo razonable, no una garantía.
 *
 * LO QUE DE VERDAD ACOTA EL DAÑO no está acá sino en el producto: lo que se le
 * pide al cliente es una CONTRASEÑA DE APLICACIÓN de Google, que él genera para
 * GOLDPAW y revoca cuando quiera sin tocar su cuenta, y el lector abre la
 * casilla en solo lectura y filtrada por el remitente que él elija.
 *
 * AES-256-GCM y no CBC: GCM viene con autenticación incluida, así que un texto
 * cifrado manipulado falla al descifrar en vez de devolver basura que después
 * se usa como contraseña.
 *
 * EL MISMO FORMATO LO LEE PYTHON (colector/cripto.py). Si alguno de los dos
 * cambia, el colector deja de poder abrir las casillas de todos los clientes a
 * la vez. El formato es deliberadamente simple para que eso no pase:
 *
 *     base64( nonce[12] || tag[16] || cifrado )
 */

declare(strict_types=1);

// =====================  EDITA ESTO  =======================================
// Dónde vive la llave. Un archivo y no una constante del código: así no entra
// al repo por accidente, y rotarla no necesita un deploy.
//
//   head -c 32 /dev/urandom | base64 > /etc/goldpaw/cripto.key
//   chown root:www-data /etc/goldpaw/cripto.key && chmod 640 /etc/goldpaw/cripto.key
//
// chmod 640 y no 400 porque la leen DOS: www-data (el CRM, que guarda) y root
// (el colector, que descifra). Y la carpeta tiene que dejar ENTRAR a www-data
// (chmod 750, grupo www-data) -- sin eso file_exists() contesta false igual que
// si el archivo no estuviera, que ya nos costó una hora el 20/09/2026 con la
// clave de Firebase.
defined('CRIPTO_LLAVE_ARCHIVO') || define('CRIPTO_LLAVE_ARCHIVO', '/etc/goldpaw/cripto.key');
// ==========================================================================

if (!function_exists('cripto_llave')) {

    /** La llave en binario, o null si no está. Se lee una vez por request. */
    function cripto_llave(): ?string
    {
        if (array_key_exists('__cripto_llave', $GLOBALS)) { return $GLOBALS['__cripto_llave']; }
        $GLOBALS['__cripto_llave'] = null;

        if (!is_readable(CRIPTO_LLAVE_ARCHIVO)) { return null; }
        try {
            $bruto = trim((string)file_get_contents(CRIPTO_LLAVE_ARCHIVO));
            if ($bruto === '') { return null; }
            $llave = base64_decode($bruto, true);
            /* Se acepta binario crudo por si alguien la generó sin base64, pero
               32 bytes son obligatorios: una llave corta no da error al cifrar,
               da un cifrado más débil sin que nadie se entere. */
            if ($llave === false || strlen($llave) !== 32) {
                $llave = strlen($bruto) === 32 ? $bruto : null;
            }
            if ($llave === null) {
                error_log('cripto: la llave no mide 32 bytes; se ignora');
                return null;
            }
            $GLOBALS['__cripto_llave'] = $llave;
        } catch (Throwable $e) {
            error_log('cripto_llave: ' . $e->getMessage());
        }
        return $GLOBALS['__cripto_llave'];
    }

    /** ¿Se puede guardar una credencial? Lo consulta el CRM antes de ofrecerlo. */
    function cripto_disponible(): bool
    {
        return cripto_llave() !== null && function_exists('openssl_encrypt');
    }

    /**
     * Cifra. Devuelve null si no se puede, y NUNCA el texto en claro: quien
     * llama tiene que tratar el null como "no guardes nada".
     */
    function cripto_cifrar(string $claro): ?string
    {
        if ($claro === '') { return null; }
        $llave = cripto_llave();
        if ($llave === null) { return null; }
        try {
            $nonce = random_bytes(12);
            $tag   = '';
            $cif   = openssl_encrypt($claro, 'aes-256-gcm', $llave,
                                     OPENSSL_RAW_DATA, $nonce, $tag);
            if ($cif === false) { return null; }
            return base64_encode($nonce . $tag . $cif);
        } catch (Throwable $e) {
            error_log('cripto_cifrar: ' . $e->getMessage());
            return null;
        }
    }

    /** Descifra. null si la llave cambió, el dato está corrupto o lo tocaron. */
    function cripto_descifrar(?string $guardado): ?string
    {
        if ($guardado === null || trim($guardado) === '') { return null; }
        $llave = cripto_llave();
        if ($llave === null) { return null; }
        try {
            $bin = base64_decode(trim($guardado), true);
            if ($bin === false || strlen($bin) < 29) { return null; }
            $nonce = substr($bin, 0, 12);
            $tag   = substr($bin, 12, 16);
            $cif   = substr($bin, 28);
            $claro = openssl_decrypt($cif, 'aes-256-gcm', $llave,
                                     OPENSSL_RAW_DATA, $nonce, $tag);
            return $claro === false ? null : $claro;
        } catch (Throwable $e) {
            error_log('cripto_descifrar: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Cómo mostrarle al cliente lo que ya tiene guardado, sin devolvérselo.
     *
     * UNA CREDENCIAL GUARDADA NO SE LEE DE VUELTA, NI AL QUE LA CARGÓ. El CRM
     * la pide una vez y después muestra esto. Si el cliente la perdió, genera
     * otra en Google: recuperarla no es un caso de uso, y poder hacerlo
     * significa que un operador con acceso al CRM se lleva la casilla.
     */
    function cripto_tapada(?string $guardado): string
    {
        return ($guardado !== null && trim($guardado) !== '') ? '••••••••••••••••' : '';
    }
}
