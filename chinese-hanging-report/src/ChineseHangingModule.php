<?php

namespace MyCustomNamespace;

use Aura\Router\RouterContainer;
use Fig\Http\Message\RequestMethodInterface;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleGlobalInterface;
use Fisharebest\Webtrees\Module\ModuleGlobalTrait;
use Fisharebest\Webtrees\Module\ModuleInterface;
use Fisharebest\Webtrees\Module\ModuleReportInterface;
use Fisharebest\Webtrees\Module\ModuleReportTrait;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\View;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use MyCustomNamespace\http\PdfPage;

class ChineseHangingModule extends AbstractModule implements ModuleCustomInterface, ModuleReportInterface, ModuleGlobalInterface, MiddlewareInterface, RequestHandlerInterface {
    use ModuleCustomTrait;
    use ModuleReportTrait;
    use ModuleGlobalTrait;

    public const GENERATIONS = __DIR__ .  '/../data/generations.ini.php';

    /**
     * How should this module be identified in the control panel, etc.?
     * @return string
     */
    public function title(): string
    {
        return I18N::translate('Hanging pedigree');
    }


    /**
     * Additional/updated translations.
     *
     * @param string $language
     *
     * @return array<string,string>
     */
    public function customTranslations(string $language): array
    {
        $file = $this->resourcesFolder() . "langs/{$language}.php";

        return file_exists($file)
            ? require $file
            : require $this->resourcesFolder() . 'langs/en.php';
    }

    /**
     * Where does this module store its resources
     *
     * @return string
     */
    public function resourcesFolder(): string
    {
        return __DIR__ . '/../resources/';
    }

    /**
     * Name of the XML report file, relative to the resources folder.
     *
     * @return string
     */
    public function xmlFilename(): string
    {
        return 'report.xml';
    }

    /**
     * Early initialisation.  Called before most of the middleware.
     */
    public function boot(): void
    {

        View::registerNamespace($this->name(), $this->resourcesFolder() . 'views/');


        $router = app(RouterContainer::class);
        assert($router instanceof RouterContainer);

        $map = $router->getMap();
        $map->get(
                PdfPage::ROUTE_PREFIX,
                '/tree/{tree}/' . PdfPage::ROUTE_PREFIX . '/{action}',
                app(PdfPage::class)
            )
            ->allows(RequestMethodInterface::METHOD_POST);

    }

    /**
     * Raw content, to be added at the end of the <body> element.
     * Typically, this will be <script> elements.
     *
     * @return string
     */
    public function bodyContent(): string
    {
        $request = app(ServerRequestInterface::class);
        $tree = $request->getAttribute('tree');
        $default_xref = $request->getQueryParams()['xref'] ??'';
        $default_gen = $default_xref?Functions::searchGeneration($default_xref,$tree):'';
        $route = app(RouterContainer::class)->getMatcher()->match($request);
        if (isset($route->attributes['report']) && $route->attributes['report'] === '_chinese-hanging-report_') {
            $indiInputs= array();
            foreach (Functions::getAllBranches($tree) as $branch){
                $branch = $branch->toArray();
                $ancestor = Functions::searchTopAncestor($branch);
                if ($ancestor==null) continue;
                $gen = Functions::searchGeneration($ancestor,$tree);
                $indiInputs []=[$ancestor,$gen];
            }
            assert($tree instanceof Tree);
            return view("{$this->name()}::setupReport",
                [
                    'tree' => $tree,
                    'module' => $this,
                    'indiInputs' => $indiInputs,
                    'defaultIndiInput' => [$default_xref,$default_gen]
                ]);
        }
        return "";
    }


    public function headContent(): string
    {
        $request = app(ServerRequestInterface::class);
        $route = app(RouterContainer::class)->getMatcher()->match($request);
        if (isset($route->attributes['report']) && $route->attributes['report'] === '_chinese-hanging-report_') {
            return view("{$this->name()}::style", [
                'styles' => [
                    $this->assetUrl('build/jquery-ui.min.css'),
                ],
            ]);
        }
        return '';
    }

    /**
     * Process an incoming server request.
     *
     * Processes an incoming server request in order to produce a response.
     * If unable to produce the response itself, it may delegate to the provided
     * request handler to do so.
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }


    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // TODO: Implement handle() method.
    }
};
