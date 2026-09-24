<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Api\Controllers;

/**
 * Knowledge-Base-Module-Plan.md v0.1 section 5. Scope: list/view/match are
 * reachable by any authenticated principal (agents included — the whole
 * point is Tim/Watson/Cowork-style callers grounding a response), but an
 * agent caller only ever sees status=published articles regardless of
 * what it asks for — enforced in each read action, not via
 * ControllerBase's class-wide $allowedRoles (which would also have to
 * cover create/update/publish, and those stay read-only for agents).
 * create/update/publish are gated per-action to admin/operator, the same
 * pattern App_skeleton\Modules\Api\Controllers\TicketsController::
 * closeAction() uses — human-authored only for now (plan section 5: "an
 * agent can draft and submit for review, not publish directly" is a
 * future extension this controller doesn't build; today an agent calling
 * create/update/publish simply gets 403).
 */
class KbArticlesController extends ControllerBase
{
    private const WRITE_ROLES = ['admin', 'operator'];

    public function indexAction()
    {
        $conditions = [];
        $bind       = [];

        $enquiryType = trim((string) $this->request->getQuery('enquiry_type', 'string', ''));

        if ($enquiryType !== '') {
            $type = \KbEnquiryTypes::findFirst(['conditions' => 'name = :name:', 'bind' => ['name' => $enquiryType]]);
            $conditions[]            = 'enquiry_type_id = :enquiry_type_id:';
            $bind['enquiry_type_id'] = $type ? (int) $type->id : 0;
        }

        $productRef = trim((string) $this->request->getQuery('product_ref', 'string', ''));

        if ($productRef !== '') {
            $conditions[]        = 'product_ref = :product_ref:';
            $bind['product_ref'] = $productRef;
        }

        $visibility = trim((string) $this->request->getQuery('visibility', 'string', ''));

        if (in_array($visibility, array_keys(\KbArticles::VISIBILITIES), true)) {
            $conditions[]        = 'visibility = :visibility:';
            $bind['visibility']  = $visibility;
        }

        $status = trim((string) $this->request->getQuery('status', 'string', ''));

        if ($this->isAgent()) {
            // Agent callers only ever see published articles, regardless
            // of what status they ask for (plan section 5/7).
            $conditions[]   = 'status = :status:';
            $bind['status'] = 'published';
        } elseif (in_array($status, array_keys(\KbArticles::STATUSES), true)) {
            $conditions[]   = 'status = :status:';
            $bind['status'] = $status;
        }

        $params = ['order' => 'id DESC'];

        if ($conditions) {
            $params['conditions'] = implode(' AND ', $conditions);
            $params['bind']       = $bind;
        }

        $articles = \KbArticles::find($params);

        return $this->response->setJsonContent([
            'articles' => array_map([$this, 'serialize'], iterator_to_array($articles)),
        ]);
    }

    public function viewAction($id)
    {
        $article = \KbArticles::findFirstById($id);

        if (!$article || ($this->isAgent() && $article->status !== 'published')) {
            $this->response->setStatusCode(404, 'Not Found');

            return $this->response->setJsonContent(['error' => 'Not found']);
        }

        return $this->response->setJsonContent(['article' => $this->serialize($article)]);
    }

    /**
     * The mechanism the plan (section 5) is actually built for: given an
     * enquiry type (required — see the plan section 1's core requirement
     * that every enquiry match against a structured type, not a blind
     * search) and optional free text, returns the best-matching published
     * article(s) for that type. v1, per plan section 5: type filter plus
     * a simple keyword rank on title/summary/body, no embeddings. Always
     * published-only, for every caller (an unfinished article is never a
     * qualified answer to suggest, human or agent).
     *
     * Ranking is intentionally simple (title match counts more than
     * summary, more than body) and uses LOWER()/LIKE rather than
     * Postgres's ILIKE — the portable equivalent CODING-STANDARDS.md's
     * "Database portability" section asks new code to prefer, unlike
     * ListView's existing (grandfathered) ILIKE-based search.
     */
    public function matchAction()
    {
        $enquiryTypeName = trim((string) $this->request->getQuery('enquiry_type', 'string', ''));

        if ($enquiryTypeName === '') {
            $this->response->setStatusCode(422, 'Unprocessable Entity');

            return $this->response->setJsonContent(['error' => 'enquiry_type is required']);
        }

        $type = \KbEnquiryTypes::findFirst(['conditions' => 'name = :name:', 'bind' => ['name' => $enquiryTypeName]]);

        if (!$type) {
            return $this->response->setJsonContent(['articles' => []]);
        }

        $candidates = \KbArticles::find([
            'conditions' => 'enquiry_type_id = :enquiry_type_id: AND status = :status:',
            'bind'       => ['enquiry_type_id' => (int) $type->id, 'status' => 'published'],
            'order'      => 'id DESC',
        ]);

        $q     = trim((string) $this->request->getQuery('q', 'string', ''));
        $terms = $q !== '' ? array_filter(preg_split('/\s+/', mb_strtolower($q)) ?: []) : [];

        $ranked = [];

        foreach ($candidates as $article) {
            $score = 0;

            if ($terms) {
                $title   = mb_strtolower((string) $article->title);
                $summary = mb_strtolower((string) $article->summary);
                $body    = mb_strtolower((string) $article->body);

                foreach ($terms as $term) {
                    if (str_contains($title, $term)) {
                        $score += 3;
                    }

                    if (str_contains($summary, $term)) {
                        $score += 2;
                    }

                    if (str_contains($body, $term)) {
                        $score += 1;
                    }
                }

                // No term matched anything at all — not a match, keyword
                // filtering out irrelevant results being the entire point
                // of supplying q in the first place.
                if ($score === 0) {
                    continue;
                }
            }

            $ranked[] = ['score' => $score, 'article' => $article];
        }

        usort($ranked, fn ($a, $b) => $b['score'] <=> $a['score'] ?: (int) $b['article']->id <=> (int) $a['article']->id);

        $ranked = array_slice($ranked, 0, 10);

        return $this->response->setJsonContent([
            'articles' => array_map(fn ($r) => $this->serialize($r['article']), $ranked),
        ]);
    }

    public function createAction()
    {
        if (!$this->request->isPost()) {
            $this->response->setStatusCode(405, 'Method Not Allowed');

            return $this->response->setJsonContent(['error' => 'POST required']);
        }

        if ($forbidden = $this->requireWriteRole()) {
            return $forbidden;
        }

        $body  = $this->getJsonBody();
        $title = trim((string) ($body['title'] ?? ''));

        if ($title === '') {
            $this->response->setStatusCode(422, 'Unprocessable Entity');

            return $this->response->setJsonContent(['error' => 'title is required']);
        }

        $enquiryTypeId = isset($body['enquiry_type_id']) ? (int) $body['enquiry_type_id'] : 0;
        $enquiryType   = $enquiryTypeId ? \KbEnquiryTypes::findFirstById($enquiryTypeId) : null;

        if (!$enquiryType) {
            $this->response->setStatusCode(422, 'Unprocessable Entity');

            return $this->response->setJsonContent(['error' => 'enquiry_type_id must reference an existing enquiry type']);
        }

        $visibility = isset($body['visibility']) ? (string) $body['visibility'] : 'internal';

        $article                     = new \KbArticles();
        $article->title              = $title;
        $article->body               = isset($body['body']) ? (string) $body['body'] : '';
        $article->summary            = isset($body['summary']) ? (string) $body['summary'] : null;
        $article->enquiry_type_id    = $enquiryType->id;
        $article->product_ref        = isset($body['product_ref']) ? (string) $body['product_ref'] : null;
        $article->visibility         = isset(\KbArticles::VISIBILITIES[$visibility]) ? $visibility : 'internal';
        $article->status             = 'draft';
        $article->created_by_user_id = $this->principal['user_id'];
        $article->updated_by_user_id = $this->principal['user_id'];

        if (!$article->save()) {
            $this->response->setStatusCode(422, 'Unprocessable Entity');

            return $this->response->setJsonContent(['error' => implode(', ', $article->getMessages())]);
        }

        $article->refresh();

        $this->response->setStatusCode(201, 'Created');

        return $this->response->setJsonContent(['article' => $this->serialize($article)]);
    }

    public function updateAction($id)
    {
        if (!$this->request->isPost()) {
            $this->response->setStatusCode(405, 'Method Not Allowed');

            return $this->response->setJsonContent(['error' => 'POST required']);
        }

        if ($forbidden = $this->requireWriteRole()) {
            return $forbidden;
        }

        $article = \KbArticles::findFirstById($id);

        if (!$article) {
            $this->response->setStatusCode(404, 'Not Found');

            return $this->response->setJsonContent(['error' => 'Not found']);
        }

        $body = $this->getJsonBody();

        if (array_key_exists('title', $body)) {
            $title = trim((string) $body['title']);

            if ($title === '') {
                $this->response->setStatusCode(422, 'Unprocessable Entity');

                return $this->response->setJsonContent(['error' => 'title cannot be empty']);
            }

            $article->title = $title;
        }

        if (array_key_exists('body', $body)) {
            $article->body = (string) $body['body'];
        }

        if (array_key_exists('summary', $body)) {
            $article->summary = $body['summary'] !== null ? (string) $body['summary'] : null;
        }

        if (array_key_exists('enquiry_type_id', $body)) {
            $enquiryType = \KbEnquiryTypes::findFirstById((int) $body['enquiry_type_id']);

            if (!$enquiryType) {
                $this->response->setStatusCode(422, 'Unprocessable Entity');

                return $this->response->setJsonContent(['error' => 'enquiry_type_id must reference an existing enquiry type']);
            }

            $article->enquiry_type_id = $enquiryType->id;
        }

        if (array_key_exists('product_ref', $body)) {
            $article->product_ref = $body['product_ref'] !== null ? (string) $body['product_ref'] : null;
        }

        if (array_key_exists('visibility', $body) && isset(\KbArticles::VISIBILITIES[$body['visibility']])) {
            $article->visibility = (string) $body['visibility'];
        }

        $article->updated_by_user_id = $this->principal['user_id'];

        if (!$article->save()) {
            $this->response->setStatusCode(422, 'Unprocessable Entity');

            return $this->response->setJsonContent(['error' => implode(', ', $article->getMessages())]);
        }

        return $this->response->setJsonContent(['article' => $this->serialize($article)]);
    }

    /**
     * Human-authored publish, per plan section 5 — sets status +
     * published_at, same shape as the backend's own publishAction().
     */
    public function publishAction($id)
    {
        if (!$this->request->isPost()) {
            $this->response->setStatusCode(405, 'Method Not Allowed');

            return $this->response->setJsonContent(['error' => 'POST required']);
        }

        if ($forbidden = $this->requireWriteRole()) {
            return $forbidden;
        }

        $article = \KbArticles::findFirstById($id);

        if (!$article) {
            $this->response->setStatusCode(404, 'Not Found');

            return $this->response->setJsonContent(['error' => 'Not found']);
        }

        $article->status             = 'published';
        $article->published_at       = date('Y-m-d H:i:s');
        $article->updated_by_user_id = $this->principal['user_id'];

        if (!$article->save()) {
            $this->response->setStatusCode(422, 'Unprocessable Entity');

            return $this->response->setJsonContent(['error' => implode(', ', $article->getMessages())]);
        }

        return $this->response->setJsonContent(['article' => $this->serialize($article)]);
    }

    private function isAgent(): bool
    {
        $agentRoleId = \Roles::idsByNames(['agent'])[0] ?? null;

        return $agentRoleId !== null && (int) $this->principal['role_id'] === $agentRoleId;
    }

    /** @return \Phalcon\Http\ResponseInterface|null null when the caller is allowed through */
    private function requireWriteRole()
    {
        $allowedRoleIds = \Roles::idsByNames(self::WRITE_ROLES);

        if (!in_array($this->principal['role_id'], $allowedRoleIds, true)) {
            $this->response->setStatusCode(403, 'Forbidden');

            return $this->response->setJsonContent(['error' => 'Forbidden']);
        }

        return null;
    }

    private function serialize(\KbArticles $article): array
    {
        return [
            'id'                 => (int) $article->id,
            'title'              => $article->title,
            'body'               => $article->body,
            'summary'            => $article->summary,
            'enquiry_type_id'    => $article->enquiry_type_id !== null ? (int) $article->enquiry_type_id : null,
            'enquiry_type'       => $article->EnquiryType ? $article->EnquiryType->name : null,
            'product_ref'        => $article->product_ref,
            'visibility'         => $article->visibility,
            'status'             => $article->status,
            'published_at'       => $article->published_at,
            'created_by_user_id' => $article->created_by_user_id !== null ? (int) $article->created_by_user_id : null,
            'updated_by_user_id' => $article->updated_by_user_id !== null ? (int) $article->updated_by_user_id : null,
            'created_at'         => $article->created_at,
            'updated_at'         => $article->updated_at,
        ];
    }
}
