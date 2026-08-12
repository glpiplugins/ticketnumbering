<?php

declare(strict_types=1);

namespace GlpiPlugin\Ticketnumbering\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Ticketnumbering\Config\Settings;
use GlpiPlugin\Ticketnumbering\Service\NumberPattern;
use GlpiPlugin\Ticketnumbering\Service\TicketNumberer;
use Session;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Configuration page of the plugin, reachable from Setup > Plugins ("Configure").
 *
 * Authorization uses the core `config` right: the ticket number format is a
 * global system setting, exactly the scope that right already describes.
 * CSRF protection for the POST is applied by GLPI's kernel CSRF listener; the
 * form carries the token through the `fields.csrfField()` macro.
 */
final class ConfigController extends AbstractController
{
    #[Route('/config', name: 'config', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        if (!Session::haveRight('config', READ)) {
            throw new AccessDeniedHttpException();
        }

        $errors = [];
        $pattern = Settings::storedPattern();
        $enabled = Settings::isEnabled();

        if ($request->isMethod(Request::METHOD_POST)) {
            if (!Session::haveRight('config', UPDATE)) {
                throw new AccessDeniedHttpException();
            }

            $input = [
                'pattern' => (string) $request->request->get('pattern', ''),
                'enabled' => (int) $request->request->get('enabled', 0),
            ];

            $errors = Settings::save($input);

            if ($errors === []) {
                Session::addMessageAfterRedirect(
                    __('Ticket numbering configuration saved.', 'ticketnumbering'),
                    true,
                    INFO
                );

                return new RedirectResponse($request->getUri());
            }

            // Keep the rejected input visible so it can be corrected.
            $pattern = NumberPattern::normalize($input['pattern']);
            $enabled = $input['enabled'] === 1;
        }

        return $this->render('@ticketnumbering/pages/config.html.twig', [
            'header_params'      => [
                __('Ticket Numbering', 'ticketnumbering'),
                '',
                'config',
                'plugins',
            ],
            'can_update'         => (bool) Session::haveRight('config', UPDATE),
            'errors'             => $errors,
            'pattern'            => $pattern,
            'enabled'            => $enabled,
            'preview'            => NumberPattern::isValid($pattern) ? NumberPattern::example($pattern) : '',
            'placeholders'       => NumberPattern::placeholderHelp(),
            'presets'            => NumberPattern::presets(),
            'default_pattern'    => Settings::DEFAULT_PATTERN,
            'max_id'             => NumberPattern::MAX_ID,
            'status'             => TicketNumberer::status(),
        ]);
    }
}
