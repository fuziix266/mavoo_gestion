<?php
declare(strict_types=1);

namespace Whatsapp\Service;

use Whatsapp\Model\WhatsappLogin;
use Whatsapp\Model\WhatsappLoginTable;
use Whatsapp\Model\WapiLid;
use Whatsapp\Model\WapiLidTable;

class WhatsappAuthService
{
    private $loginTable;
    private $lidTable;
    private $wapiService;
    
    public function __construct(
        WhatsappLoginTable $loginTable, 
        WapiLidTable $lidTable,
        WapiService $wapiService
    ) {
        $this->loginTable = $loginTable;
        $this->lidTable = $lidTable;
        $this->wapiService = $wapiService;
    }

    /**
     * Valida un código numérico enviado al WhatsApp.
     * Si es válido, marca la sesión como verificada y guarda el LID remitente.
     */
    public function validateLoginCode(string $code, string $remoteJid): bool
    {
        $this->loginTable->cleanExpired();
        
        $rowset = $this->loginTable->getLoginByCode($code);
        $login = $rowset->current();
        
        if ($login) {
            $login->is_verified = true;
            $login->lid = $remoteJid;
            $this->loginTable->saveLogin($login);
            
            // Enviar mensaje de confirmación
            $this->wapiService->sendText($remoteJid, "✅ ¡Identidad Confirmada!\nYa puedes continuar en tu navegador.");
            return true;
        }
        
        return false;
    }

    /**
     * Valida una petición de verificación de segundo factor (SMS/WSP binding).
     */
    public function validateDoubleFactorCode(string $code, string $remoteJid): bool
    {
        // Limpiamos opcionalmente aquí
        $lidRecord = $this->lidTable->getByLid($remoteJid);
        
        if ($lidRecord && $lidRecord->verification_code == $code && !$lidRecord->is_verified) {
            $lidRecord->is_verified = true;
            $this->lidTable->saveLid($lidRecord);
            
            $this->wapiService->sendText($remoteJid, "✅ Número verificado correctamente.");
            return true;
        }
        
        return false;
    }
    
    public function checkSessionStatus(string $sessionId, string $scope): array
    {
        $rowset = $this->loginTable->getLoginBySessionAndScope($sessionId, $scope);
        $login = $rowset->current();
        
        if (!$login) {
            return ['status' => 'not_found'];
        }
        
        return [
            'status'      => $login->is_verified ? 'verified' : 'pending',
            'is_verified' => $login->is_verified,
            'lid'         => $login->lid
        ];
    }
}
