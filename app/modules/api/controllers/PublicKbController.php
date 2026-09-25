<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Api\Controllers;

/**
 * Public, unauthenticated FAQ feed for the website (Knowledge-Base-
 * Module-Plan.md v0.1 section 5's "separate, unauthenticated public
 * endpoint... for the website's own FAQ page to pull from directly").
 * Mirrors PublicIntakeController's pattern exactly: its own
 * \Phalcon\Mvc\Controller (not ControllerBase, so it never goes through
 * the principal/role check every other api-module controller enforces),
 * registered directly via Module::registerRoutes() rather than the
 * generic /api/:controller/:action shape, CORS headers for the separate
 * origin(s) that actually call it.
 *
 * Unlike PublicIntakeController this is read-only, so there's no
 * token/enumeration concern to guard against — visibility=public AND
 * status=published is hardcoded into the query itself (not just a
 * default filter a caller could override), so this can never leak an
 * internal or draft article no matter what's passed in.
 */
class PublicKbController extends \Phalcon\Mvc\Controller
{
    protected function onConstruct()
    {
        $this->view->disable();

        $origin = (string) $this->request->getHeader('Origin');

        if (in_array($origin, ['https://deploy.xten.au', 'https://xten.au'], true)) {
            $this->response->setHeader('Access-Control-Allow-Origin', $origin);
        }

        $this->response->setHeader('Access-Control-Allow-Methods', 'GET, OPTIONS');
        $this->response->setHeader('Access-Control-Allow-Headers', 'Content-Type, Accept');
        $this->response->setContentType('application/json', 'UTF-8');

        if ($this->request->getMethod() === 'OPTIONS') {
            $this->response->setStatusCode(204);
            $this->response->send();
            exit;
        }
    }

    /**
     * GET /api/public-kb/faq — optionally narrowed with ?enquiry_type=.
     * Hardcoded to visibility=public AND status=published; nothing a
     * caller passes can widen that.
     */
    public function faqAction()
    {
        $conditions = ["visibility = 'public'", "status = 'published'"];
        $bind       = [];

        $enquiryType = trim((string) $this->request->getQuery('enquiry_type', 'string', ''));

        if ($enquiryType !== '') {
            $type = \KbEnquiryTypes::findFirst(['conditions' => 'name = :name:', 'bind' => ['name' => $enquiryType]]);
            $conditions[]            = 'enquiry_type_id = :enquiry_type_id:';
            $bind['enquiry_type_id'] = $type ? (int) $type->id : 0;
        }

        $articles = \KbArticles::find([
            'conditions' => implode(' AND ', $conditions),
            'bind'       => $bind,
            'order'      => 'id DESC',
        ]);

        return $this->response->setJsonContent([
            'articles' => array_map([$this, 'serialize'], iterator_to_array($articles)),
        ]);
    }

    private function serialize(\KbArticles $article): array
    {
        return [
            'id'           => (int) $article->id,
            'title'        => $article->title,
            'summary'      => $article->summary,
            'body'         => $article->body,
            'enquiry_type' => $article->EnquiryType ? $article->EnquiryType->name : null,
            'product_ref'  => $article->product_ref,
            'published_at' => $article->published_at,
        ];
    }
}
