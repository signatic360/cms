<?php
/**
 * Copyright (C) 2020 Xibo Signage Ltd
 *
 * Xibo - Digital Signage - http://www.xibo.org.uk
 *
 * This file is part of Xibo.
 *
 * Xibo is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * Xibo is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Xibo.  If not, see <http://www.gnu.org/licenses/>.
 */
namespace Xibo\Controller;

use Slim\Http\Response as Response;
use Slim\Http\ServerRequest as Request;
use Slim\Views\Twig;
use Xibo\Factory\LayoutFactory;
use Xibo\Factory\TagFactory;
use Xibo\Helper\SanitizerService;
use Xibo\Service\ConfigServiceInterface;
use Xibo\Service\LogServiceInterface;
use Xibo\Support\Exception\AccessDeniedException;
use Xibo\Support\Exception\GeneralException;

/**
 * Class Template
 * @package Xibo\Controller
 */
class Template extends Base
{
    /**
     * @var LayoutFactory
     */
    private $layoutFactory;

    /**
     * @var TagFactory
     */
    private $tagFactory;

    /**
     * Set common dependencies.
     * @param LogServiceInterface $log
     * @param SanitizerService $sanitizerService
     * @param \Xibo\Helper\ApplicationState $state
     * @param \Xibo\Entity\User $user
     * @param \Xibo\Service\HelpServiceInterface $help
     * @param ConfigServiceInterface $config
     * @param LayoutFactory $layoutFactory
     * @param TagFactory $tagFactory
     * @param Twig $view
     */
    public function __construct($log, $sanitizerService, $state, $user, $help, $config, $layoutFactory, $tagFactory, Twig $view)
    {
        $this->setCommonDependencies($log, $sanitizerService, $state, $user, $help, $config, $view);

        $this->layoutFactory = $layoutFactory;
        $this->tagFactory = $tagFactory;
    }

    /**
     * Display page logic
     * @param Request $request
     * @param Response $response
     * @return \Psr\Http\Message\ResponseInterface|Response
     * @throws GeneralException
     * @throws \Xibo\Support\Exception\ControllerNotImplemented
     */
    function displayPage(Request $request, Response $response)
    {
        // Call to render the template
        $this->getState()->template = 'template-page';

        return $this->render($request, $response);
    }

    /**
     * Data grid
     *
     * @SWG\Get(
     *  path="/template",
     *  operationId="templateSearch",
     *  tags={"template"},
     *  summary="Template Search",
     *  description="Search templates this user has access to",
     *  @SWG\Response(
     *      response=200,
     *      description="successful operation",
     *      @SWG\Schema(
     *          type="array",
     *          @SWG\Items(ref="#/definitions/Layout")
     *      )
     *  )
     * )
     * @param Request $request
     * @param Response $response
     * @return \Psr\Http\Message\ResponseInterface|Response
     * @throws GeneralException
     * @throws \Xibo\Support\Exception\ControllerNotImplemented
     * @throws \Xibo\Support\Exception\NotFoundException
     */
    function grid(Request $request, Response $response)
    {
        $sanitizedQueryParams = $this->getSanitizer($request->getQueryParams());
        // Embed?
        $embed = ($sanitizedQueryParams->getString('embed') != null) ? explode(',', $sanitizedQueryParams->getString('embed')) : [];

        $templates = $this->layoutFactory->query($this->gridRenderSort($request), $this->gridRenderFilter([
            'excludeTemplates' => 0,
            'tags' => $sanitizedQueryParams->getString('tags'),
            'layoutId' => $sanitizedQueryParams->getInt('templateId'),
            'layout' => $sanitizedQueryParams->getString('template')
        ], $request));

        foreach ($templates as $template) {
            /* @var \Xibo\Entity\Layout $template */

            if (in_array('regions', $embed)) {
                $template->load([
                    'loadPlaylists' => in_array('playlists', $embed),
                    'loadCampaigns' => in_array('campaigns', $embed),
                    'loadPermissions' => in_array('permissions', $embed),
                    'loadTags' => in_array('tags', $embed),
                    'loadWidgets' => in_array('widgets', $embed)
                ]);
            }

            if ($this->isApi($request))
                break;

            $template->includeProperty('buttons');

            $template->thumbnail = '';

            if ($template->backgroundImageId != 0) {
                $download = $this->urlFor($request,'layout.download.background', ['id' => $template->layoutId]) . '?preview=1';
                $template->thumbnail = '<a class="img-replace" data-toggle="lightbox" data-type="image" href="' . $download . '"><img src="' . $download . '&width=100&height=56" /></i></a>';
            }

            // Parse down for description
            $template->descriptionWithMarkup = \Parsedown::instance()->text($template->description);

            if ($this->getUser()->featureEnabled('template.modify')
                && $this->getUser()->checkEditable($template)
            ) {
                // Design Button
                $template->buttons[] = array(
                    'id' => 'layout_button_design',
                    'linkType' => '_self', 'external' => true,
                    'url' => $this->urlFor($request,'layout.designer', array('id' => $template->layoutId)),
                    'text' => __('Alter Template')
                );

                // Edit Button
                $template->buttons[] = array(
                    'id' => 'layout_button_edit',
                    'url' => $this->urlFor($request,'layout.edit.form', ['id' => $template->layoutId]),
                    'text' => __('Edit')
                );

                // Copy Button
                $template->buttons[] = array(
                    'id' => 'layout_button_copy',
                    'url' => $this->urlFor($request,'layout.copy.form', ['id' => $template->layoutId]),
                    'text' => __('Copy')
                );
            }

            // Extra buttons if have delete permissions
            if ($this->getUser()->featureEnabled('template.modify')
                && $this->getUser()->checkDeleteable($template)) {
                // Delete Button
                $template->buttons[] = [
                    'id' => 'layout_button_delete',
                    'url' => $this->urlFor($request,'layout.delete.form', ['id' => $template->layoutId]),
                    'text' => __('Delete'),
                    'multi-select' => true,
                    'dataAttributes' => [
                        ['name' => 'commit-url', 'value' => $this->urlFor($request,'layout.delete', ['id' => $template->layoutId])],
                        ['name' => 'commit-method', 'value' => 'delete'],
                        ['name' => 'id', 'value' => 'layout_button_delete'],
                        ['name' => 'text', 'value' => __('Delete')],
                        ['name' => 'sort-group', 'value' => 1],
                        ['name' => 'rowtitle', 'value' => $template->layout]
                    ]
                ];
            }

            $template->buttons[] = ['divider' => true];

            // Extra buttons if we have modify permissions
            if ($this->getUser()->featureEnabled('template.modify')
                && $this->getUser()->checkPermissionsModifyable($template)) {
                // Permissions button
                $template->buttons[] = [
                    'id' => 'layout_button_permissions',
                    'url' => $this->urlFor($request,'user.permissions.form', ['entity' => 'Campaign', 'id' => $template->campaignId]),
                    'text' => __('Share'),
                    'multi-select' => true,
                    'dataAttributes' => [
                        ['name' => 'commit-url', 'value' => $this->urlFor($request,'user.permissions.multi', ['entity' => 'Campaign', 'id' => $template->campaignId])],
                        ['name' => 'commit-method', 'value' => 'post'],
                        ['name' => 'id', 'value' => 'layout_button_permissions'],
                        ['name' => 'text', 'value' => __('Share')],
                        ['name' => 'rowtitle', 'value' => $template->layout],
                        ['name' => 'sort-group', 'value' => 2],
                        ['name' => 'custom-handler', 'value' => 'XiboMultiSelectPermissionsFormOpen'],
                        ['name' => 'custom-handler-url', 'value' => $this->urlFor($request,'user.permissions.multi.form', ['entity' => 'Campaign'])],
                        ['name' => 'content-id-name', 'value' => 'campaignId']
                    ]
                ];
            }

            if ($this->getUser()->featureEnabled('layout.export')) {
                $template->buttons[] = ['divider' => true];

                // Export Button
                $template->buttons[] = array(
                    'id' => 'layout_button_export',
                    'linkType' => '_self',
                    'external' => true,
                    'url' => $this->urlFor($request, 'layout.export', ['id' => $template->layoutId]),
                    'text' => __('Export')
                );
            }
        }

        $this->getState()->template = 'grid';
        $this->getState()->recordsTotal = $this->layoutFactory->countLast();
        $this->getState()->setData($templates);

        return $this->render($request, $response);
    }

    /**
     * Template Form
     * @param Request $request
     * @param Response $response
     * @param $id
     * @return \Psr\Http\Message\ResponseInterface|Response
     * @throws AccessDeniedException
     * @throws GeneralException
     * @throws \Xibo\Support\Exception\ControllerNotImplemented
     * @throws \Xibo\Support\Exception\NotFoundException
     */
    function addTemplateForm(Request $request, Response $response, $id)
    {
        // Get the layout
        $layout = $this->layoutFactory->getById($id);

        // Check Permissions
        if (!$this->getUser()->checkViewable($layout)) {
            throw new AccessDeniedException(__('You do not have permissions to view this layout'));
        }

        $this->getState()->template = 'template-form-add-from-layout';
        $this->getState()->setData([
            'layout' => $layout,
            'tags' => $this->tagFactory->getTagsWithValues($layout),
            'help' => $this->getHelp()->link('Template', 'Add')
        ]);

        return $this->render($request, $response);
    }

    /**
     * Add template
     * @param Request $request
     * @param Response $response
     * @param $id
     * @return \Psr\Http\Message\ResponseInterface|Response
     * @throws AccessDeniedException
     * @throws GeneralException
     * @throws \Xibo\Support\Exception\ControllerNotImplemented
     * @throws \Xibo\Support\Exception\NotFoundException
     * @SWG\Post(
     *  path="/template/{layoutId}",
     *  operationId="template.add.from.layout",
     *  tags={"template"},
     *  summary="Add a template from a Layout",
     *  description="Use the provided layout as a base for a new template",
     *  @SWG\Parameter(
     *      name="layoutId",
     *      in="path",
     *      description="The Layout ID",
     *      type="integer",
     *      required=true
     *   ),
     *  @SWG\Parameter(
     *      name="includeWidgets",
     *      in="formData",
     *      description="Flag indicating whether to include the widgets in the Template",
     *      type="integer",
     *      required=true
     *   ),
     *  @SWG\Parameter(
     *      name="name",
     *      in="formData",
     *      description="The Template Name",
     *      type="string",
     *      required=true
     *   ),
     *  @SWG\Parameter(
     *      name="tags",
     *      in="formData",
     *      description="Comma separated list of Tags for the template",
     *      type="string",
     *      required=false
     *   ),
     *  @SWG\Parameter(
     *      name="description",
     *      in="formData",
     *      description="A description of the Template",
     *      type="string",
     *      required=false
     *   ),
     *  @SWG\Response(
     *      response=201,
     *      description="successful operation",
     *      @SWG\Schema(ref="#/definitions/Layout"),
     *      @SWG\Header(
     *          header="Location",
     *          description="Location of the new record",
     *          type="string"
     *      )
     *  )
     * )
     */
    function add(Request $request, Response $response, $id)
    {
        // Get the layout
        $layout = $this->layoutFactory->getById($id);

        // Check Permissions
        if (!$this->getUser()->checkViewable($layout)) {
            throw new AccessDeniedException(__('You do not have permissions to view this layout'));
        }

        $sanitizedParams = $this->getSanitizer($request->getParams());
        // Should the copy include the widgets
        $includeWidgets = ($sanitizedParams->getCheckbox('includeWidgets') == 1);

        // Load without anything
        $layout->load([
            'loadPlaylists' => true,
            'loadWidgets' => $includeWidgets,
            'playlistIncludeRegionAssignments' => false,
            'loadTags' => false,
            'loadPermissions' => false,
            'loadCampaigns' => false
        ]);
        $originalLayout = $layout;

        $layout = clone $layout;

        $layout->layout = $sanitizedParams->getString('name');
        if ($this->getUser()->featureEnabled('tag.tagging')) {
            $layout->tags = $this->tagFactory->tagsFromString($sanitizedParams->getString('tags'));
        } else {
            $layout->tags = [];
        }
        $layout->tags[] = $this->tagFactory->getByTag('template');

        $layout->description = $sanitizedParams->getString('description');
        $layout->setOwner($this->getUser()->userId, true);
        $layout->save();

        if ($includeWidgets) {
            // Sub-Playlist
            foreach ($layout->regions as $region) {
                // Match our original region id to the id in the parent layout
                $original = $originalLayout->getRegion($region->getOriginalValue('regionId'));

                // Make sure Playlist closure table from the published one are copied over
                $original->getPlaylist()->cloneClosureTable($region->getPlaylist()->playlistId);
            }
        }

        // Return
        $this->getState()->hydrate([
            'httpStatus' => 201,
            'message' => sprintf(__('Saved %s'), $layout->layout),
            'id' => $layout->layoutId,
            'data' => $layout
        ]);

        return $this->render($request, $response);
    }
}
