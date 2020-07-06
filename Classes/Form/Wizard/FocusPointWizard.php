<?php
declare(strict_types=1);

namespace Blueways\BwFocuspointImages\Form\Wizard;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use Blueways\BwFocuspointImages\Utility\HelperUtility;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;

/**
 * Wizard for rendering image manipulation view
 */
class FocusPointWizard
{

    /**
     * @param \Psr\Http\Message\ServerRequestInterface $request
     * @param \Psr\Http\Message\ResponseInterface $response
     * @return \Psr\Http\Message\ResponseInterface
     * @throws \TYPO3\CMS\Extbase\Configuration\Exception\InvalidConfigurationTypeException
     * @throws \TYPO3\CMS\Fluid\View\Exception\InvalidTemplateResourceException
     */
    public function getWizardAction(ServerRequestInterface $request, ResponseInterface $response)
    {
        if (!$this->isSignatureValid($request)) {
            return $response->withStatus(403);
        }

        $typoScript = HelperUtility::getTypoScript();

        $templateView = GeneralUtility::makeInstance(StandaloneView::class);
        $templateView->setLayoutRootPaths($typoScript['view']['layoutRootPaths']);
        $templateView->setPartialRootPaths($typoScript['view']['partialRootPaths']);
        $templateView->setTemplateRootPaths($typoScript['view']['templateRootPaths']);
        $templateView->setTemplate('FocuspointWizard');

        $queryParams = json_decode($request->getQueryParams()['arguments'], true);
        $fileUid = $queryParams['image'];
        $image = null;
        if (MathUtility::canBeInterpretedAsInteger($fileUid)) {
            try {
                $image = ResourceFactory::getInstance()->getFileObject($fileUid);
            } catch (FileDoesNotExistException $e) {
                return $response->withStatus(404);
            }
        }

        $linkBrowsers = $this->linkBrowser($queryParams['pid'], $typoScript);
        $tree = $this->treeAction($queryParams['pid']);
        $viewData = [
            'image' => $image,
            'focusPoints' => $queryParams['focusPoints'],
            'linkBrowsers' => $linkBrowsers,
            'tree' => $tree
        ];
        $templateView->assignMultiple($viewData);
        $content = $templateView->render();
        $response->getBody()->write($content);

        return $response;
    }

    /**
     * Check if hmac signature is correct
     *
     * @param ServerRequestInterface $request the request with the GET parameters
     * @return bool
     */
    protected function isSignatureValid(ServerRequestInterface $request): bool
    {
        $token = GeneralUtility::hmac($request->getQueryParams()['arguments'], 'ajax_wizard_focuspoint');
        return $token === $request->getQueryParams()['signature'];
    }

    protected function linkBrowser(int $pid, array $typoscript)
    {
        $linkFields = array_filter($typoscript['settings']['fields'], function ($field) {
            return isset($field['type']) && $field['type'] === 'link';
        });

        // abort if no link browser is required
        if (!count($linkFields)) {
            return [];
        }

        // guess the entry point for linkhandler configuration and filter for ones with configuration (table name)
        $pageTsConfig = HelperUtility::getPagesTSconfig($pid);
        $linkBrowsers = array_filter($pageTsConfig['TCEMAIN']['linkHandler'], function ($linkBrowser) {
            return
                isset($linkBrowser['handler'], $linkBrowser['configuration']) && substr(strrchr($linkBrowser['handler'],
                    "\\"), 1) === 'RecordLinkHandler';
        });

        // set storage pids
        foreach ($linkBrowsers as &$linkBrowser) {
            if (isset($linkBrowser['configuration']['storagePid'])) {
                continue;
            }

            if (!isset($linkBrowser['configuration']['storagePid']) && isset($linkBrowser['configuration']['onlyPids'])) {
                $linkBrowser['configuration']['storagePid'] = $linkBrowser['configuration']['onlyPids'];
                continue;
            }

            $linkBrowser['configuration']['storagePid'] = $pid;
            $linkBrowser['configuration']['levels'] = 9;
        }

        return $linkBrowsers;
    }

    protected function treeAction(int $startingPid)
    {
        // Get starting point and page record of it
        $rootLine = BackendUtility::BEgetRootLine($startingPid);
        $startingPoint = count($rootLine) > 1 && $rootLine[0]['uid'] === 0 ? $rootLine[1]['uid'] : $startingPid;
        $pageRecord = \TYPO3\CMS\Backend\Utility\BackendUtility::getRecord(
            'pages',
            $startingPoint
        );

        // Create and initialize the tree object
        /** @var $tree \TYPO3\CMS\Backend\Tree\View\PageTreeView */
        $tree = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\\CMS\\Backend\\Tree\\View\\PageTreeView');
        $tree->init('AND ' . $GLOBALS['BE_USER']->getPagePermsClause(1));

        // Creating the icon for the current page and add it to the tree
        $html = \TYPO3\CMS\Backend\Utility\IconUtility::getSpriteIconForRecord(
            'pages',
            $pageRecord,
            array(
                'title' => $pageRecord['title']
            )
        );
        $tree->tree[] = array(
            'row' => $pageRecord,
            'HTML' => $html
        );

        // Create the page tree, from the starting point, 2 levels deep
        $depth = 9;
        $tree->getTree(
            $startingPoint,
            $depth,
            ''
        );

        return $tree->tree;
    }

    protected function pageBrowser(int $pid)
    {
        $tree = $this->treeAction($pid);
    }
}
