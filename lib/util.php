<?php
declare(strict_types=1);

class ApiError extends Exception
{
    public array $extra;
    public function __construct(string $msg, int $http = 400, array $extra = [])
    {
        parent::__construct($msg, $http);
        $this->extra = $extra;
    }
}

/** Reloj sustituible para pruebas. */
class Reloj
{
    public static ?DateTimeImmutable $fijo = null;
    public static function ahora(): DateTimeImmutable
    {
        return self::$fijo ?? new DateTimeImmutable('now', new DateTimeZone('America/Santiago'));
    }
    public static function hoy(): string { return self::ahora()->format('Y-m-d'); }
    public static function stamp(): string { return self::ahora()->format('Y-m-d H:i:s'); }
}

function rut_dv(string $cuerpo): string
{
    $suma = 0; $m = 2;
    for ($i = strlen($cuerpo) - 1; $i >= 0; $i--) {
        $suma += (int)$cuerpo[$i] * $m;
        $m = $m === 7 ? 2 : $m + 1;
    }
    $r = 11 - ($suma % 11);
    return $r === 11 ? '0' : ($r === 10 ? 'K' : (string)$r);
}

/** Devuelve "12345678-5" o null si el RUT o su dígito verificador no son válidos. */
function rut_normalizar(string $rut): ?string
{
    $s = strtoupper((string)preg_replace('/[^0-9kK]/', '', $rut));
    if (strlen($s) < 2) return null;
    $cuerpo = ltrim(substr($s, 0, -1), '0');
    $dv = substr($s, -1);
    if ($cuerpo === '' || !ctype_digit($cuerpo) || strlen($cuerpo) > 9) return null;
    return rut_dv($cuerpo) === $dv ? $cuerpo . '-' . $dv : null;
}

function texto_norm(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    return (string)preg_replace('/\s+/', ' ', $s);
}

function limpiar(mixed $v, int $max = 200): string
{
    $s = trim((string)($v ?? ''));
    $s = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
    return mb_substr($s, 0, $max, 'UTF-8');
}

function fecha_ok(mixed $s): bool
{
    if (!is_string($s)) return false;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $s);
    return $d !== false && $d->format('Y-m-d') === $s;
}

function fecha_add(string $f, int $n): string
{
    return (new DateTimeImmutable($f))->modify(sprintf('%+d day', $n))->format('Y-m-d');
}

/** 1 = lunes … 7 = domingo */
function dia_semana(string $f): int { return (int)(new DateTimeImmutable($f))->format('N'); }

function fecha_cl(string $f): string { return substr($f, 8, 2) . '-' . substr($f, 5, 2) . '-' . substr($f, 0, 4); }

function rango_fechas(string $a, string $b, int $max = 400): array
{
    $out = [];
    $d = new DateTimeImmutable($a);
    $fin = new DateTimeImmutable($b);
    while ($d <= $fin) {
        $out[] = $d->format('Y-m-d');
        if (count($out) > $max) throw new ApiError('El rango de fechas es demasiado largo.', 422);
        $d = $d->modify('+1 day');
    }
    return $out;
}

function es_en(mixed $v, array $permitidos): bool { return is_string($v) && in_array($v, $permitidos, true); }
