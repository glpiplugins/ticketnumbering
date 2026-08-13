<?php

declare(strict_types=1);

namespace GlpiPlugin\Ticketnumbering\Controller;

use Glpi\Controller\AbstractController;
use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Ticketnumbering\Config\Settings;
use GlpiPlugin\Ticketnumbering\Service\ItemNumberer;
use GlpiPlugin\Ticketnumbering\Service\NumberPattern;
use Html;
use Session;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Configuration page of the plugin, reachable from Setup > Plugins ("Configure").
 *
 * Every supported itemtype (ticket, problem, change) is configured separately on
 * the same form; a rejected pattern blocks the whole save, so the itemtypes never
 * end up half-saved.
 *
 * Authorization uses the core `config` right: the number format is a global
 * system setting, exactly the scope that right already describes.
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
        $submitted = null;

        if ($request->isMethod(Request::METHOD_POST)) {
            if (!Session::haveRight('config', UPDATE)) {
                throw new AccessDeniedHttpException();
            }

            $submitted = self::readInput($request);
            $errors = Settings::save($submitted);

            if ($errors === []) {
                Session::addMessageAfterRedirect(
                    __('Ticket numbering configuration saved.', 'ticketnumbering'),
                    true,
                    INFO
                );

                return new RedirectResponse($request->getUri());
            }
        }

        return $this->render('@ticketnumbering/pages/config.html.twig', [
            'header_params'   => [
                __('Ticket Numbering', 'ticketnumbering'),
                '',
                'config',
                'plugins',
            ],
            'can_update'      => (bool) Session::haveRight('config', UPDATE),
            'has_errors'      => $errors !== [],
            'sections'        => self::sections($errors, $submitted),
            'placeholders'    => NumberPattern::placeholderHelp(),
            'presets'         => NumberPattern::presets(),
            'default_pattern' => Settings::DEFAULT_PATTERN,
        ]);
    }

    /**
     * The submitted values, restricted to the keys the settings store knows.
     *
     * @return array<string,mixed>
     */
    private static function readInput(Request $request): array
    {
        $input = [];
        foreach (Settings::itemtypes() as $itemtype) {
            $enabledKey = Settings::enabledKey($itemtype);
            $patternKey = Settings::patternKey($itemtype);

            $input[$enabledKey] = (int) $request->request->get($enabledKey, 0);
            $input[$patternKey] = (string) $request->request->get($patternKey, '');
        }

        return $input;
    }

    /**
     * View data for one card per itemtype: the form values (the rejected input
     * when the form comes back with errors) plus the live numbering state.
     *
     * @param array<string,list<string>> $errors     per itemtype
     * @param array<string,mixed>|null   $submitted  rejected input, if any
     * @return list<array<string,mixed>>
     */
    private static function sections(array $errors, ?array $submitted): array
    {
        $sections = [];

        foreach (Settings::itemtypes() as $itemtype) {
            $enabledKey = Settings::enabledKey($itemtype);
            $patternKey = Settings::patternKey($itemtype);

            if ($submitted !== null) {
                // Keep the rejected input visible so it can be corrected.
                $pattern = NumberPattern::normalize((string) $submitted[$patternKey]);
                $enabled = (int) $submitted[$enabledKey] === 1;
            } else {
                $pattern = Settings::storedPattern($itemtype);
                $enabled = Settings::isEnabled($itemtype);
            }

            $valid = NumberPattern::isValid($pattern);
            $validUntil = $valid ? NumberPattern::validUntil($pattern) : null;

            $sections[] = [
                'itemtype'    => $itemtype,
                'label'       => ItemNumberer::labelFor($itemtype),
                'enabled_key' => $enabledKey,
                'pattern_key' => $patternKey,
                'enabled'     => $enabled,
                'pattern'     => $pattern,
                'preview'     => $valid ? NumberPattern::example($pattern) : '',
                'valid_until' => $validUntil === null ? '' : Html::convDate(date('Y-m-d', $validUntil)),
                'errors'      => $errors[$itemtype] ?? [],
                'status'      => ItemNumberer::status($itemtype),
            ];
        }

        return $sections;
    }
}
