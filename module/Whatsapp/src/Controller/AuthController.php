<?php
declare(strict_types=1);

namespace Whatsapp\Controller;

use Laminas\Mvc\Controller\AbstractRestfulController;
use Laminas\View\Model\JsonModel;
use Whatsapp\Service\WhatsappAuthService;

class AuthController extends AbstractRestfulController
{
    private $authService;

    public function __construct(WhatsappAuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Endpoint: /api/whatsapp/auth/wapilogin (POST)
     * Responde a los mensajes de los usuarios que envían el código de 6 dígitos.
     */
    public function wapiLoginAction()
    {
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return new JsonModel(['error' => 'Method not allowed', 'status' => 405]);
        }

        $data = json_decode($request->getContent(), true);

        // Simulando la estructura que enviaría el Wapi node.js
        $messageData = $data['data']['message'] ?? [];
        $extendedTextMessage = $messageData['extendedTextMessage']['text'] ?? '';
        $conversation = $messageData['conversation'] ?? '';
        
        $msgText = trim($extendedTextMessage !== '' ? $extendedTextMessage : $conversation);
        $remoteJid = $data['data']['key']['remoteJid'] ?? '';
        
        if (empty($msgText) || empty($remoteJid)) {
            return new JsonModel(['status' => 'error', 'message' => 'Faltan parámetros']);
        }
        
        $remoteJid = explode('@', $remoteJid)[0];
        
        // El usuario envía un código de 6 dígitos
        if (preg_match('/^\d{6}$/', $msgText)) {
            $isValid = $this->authService->validateLoginCode($msgText, $remoteJid);
            if ($isValid) {
                return new JsonModel(['status' => 'success', 'message' => 'Login Code Validated']);
            }
        }
        
        return new JsonModel(['status' => 'ignored']);
    }

    /**
     * Endpoint: /api/whatsapp/auth/wapilogin2 (POST)
     * Responde a los mensajes de los usuarios que envían el código de 3 dígitos (Doble factor).
     */
    public function wapiLogin2Action()
    {
        $request = $this->getRequest();
        if (!$request->isPost()) {
            return new JsonModel(['error' => 'Method not allowed'] );
        }

        $data = json_decode($request->getContent(), true);
        
        $messageData = $data['data']['message'] ?? [];
        $extendedTextMessage = $messageData['extendedTextMessage']['text'] ?? '';
        $conversation = $messageData['conversation'] ?? '';
        
        $msgText = trim($extendedTextMessage !== '' ? $extendedTextMessage : $conversation);
        $remoteJid = $data['data']['key']['remoteJid'] ?? '';
        
        if (empty($msgText) || empty($remoteJid)) {
            return new JsonModel(['status' => 'error', 'message' => 'Faltan parámetros']);
        }
        
        $remoteJid = explode('@', $remoteJid)[0];
        
        if (preg_match('/^\d{3}$/', $msgText)) {
            $isValid = $this->authService->validateDoubleFactorCode($msgText, $remoteJid);
            if ($isValid) {
                return new JsonModel(['status' => 'success', 'message' => 'Phone verified successfully']);
            }
        }
        
        return new JsonModel(['status' => 'ignored']);
    }
}
