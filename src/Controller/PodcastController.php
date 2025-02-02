<?php

declare(strict_types=1);

/*
 * (c) 2022 Michael Joyce <mjoyce@sfu.ca>
 * This source file is subject to the GPL v2, bundled
 * with this source code in the file LICENSE.
 */

namespace App\Controller;

use App\Entity\Podcast;
use App\Form\PodcastType;
use App\Repository\PodcastRepository;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Knp\Bundle\PaginatorBundle\Definition\PaginatorAwareInterface;
use Nines\MediaBundle\Controller\ImageControllerTrait;
use Nines\MediaBundle\Entity\Image;
use Nines\UtilBundle\Controller\PaginatorTrait;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/podcast")
 */
class PodcastController extends AbstractController implements PaginatorAwareInterface {
    use PaginatorTrait;
    use ImageControllerTrait;

    /**
     * @Route("/", name="podcast_index", methods={"GET"})
     *
     * @Template("podcast/index.html.twig")
     */
    public function index(Request $request, PodcastRepository $podcastRepository) : array {
        $query = $podcastRepository->indexQuery();
        $pageSize = $this->getParameter('page_size');
        $page = $request->query->getint('page', 1);

        return [
            'podcasts' => $this->paginator->paginate($query, $page, $pageSize),
        ];
    }

    /**
     * @Route("/search", name="podcast_search", methods={"GET"})
     *
     * @Template("podcast/search.html.twig")
     *
     * @return array
     */
    public function search(Request $request, PodcastRepository $podcastRepository) {
        $q = $request->query->get('q');
        if ($q) {
            $query = $podcastRepository->searchQuery($q);
            $podcasts = $this->paginator->paginate($query, $request->query->getInt('page', 1), $this->getParameter('page_size'), ['wrap-queries' => true]);
        } else {
            $podcasts = [];
        }

        return [
            'podcasts' => $podcasts,
            'q' => $q,
        ];
    }

    /**
     * @Route("/typeahead", name="podcast_typeahead", methods={"GET"})
     *
     * @return JsonResponse
     */
    public function typeahead(Request $request, PodcastRepository $podcastRepository) {
        $q = $request->query->get('q');
        if ( ! $q) {
            return new JsonResponse([]);
        }
        $data = [];

        foreach ($podcastRepository->typeaheadQuery($q)->execute() as $result) {
            $data[] = [
                'id' => $result->getId(),
                'text' => (string) $result,
            ];
        }

        return new JsonResponse($data);
    }

    /**
     * @Route("/new", name="podcast_new", methods={"GET", "POST"})
     * @Template("podcast/new.html.twig")
     * @IsGranted("ROLE_CONTENT_ADMIN")
     *
     * @return array|RedirectResponse
     */
    public function new(Request $request) {
        $podcast = new Podcast();
        $form = $this->createForm(PodcastType::class, $podcast);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager = $this->getDoctrine()->getManager();

            foreach ($podcast->getContributions() as $contribution) {
                $contribution->setPodcast($podcast);
                $entityManager->persist($contribution);
            }
            $entityManager->persist($podcast);
            $entityManager->flush();
            $this->addFlash('success', 'The new podcast has been saved.');

            return $this->redirectToRoute('podcast_show', ['id' => $podcast->getId()]);
        }

        return [
            'podcast' => $podcast,
            'form' => $form->createView(),
        ];
    }

    /**
     * @Route("/new_popup", name="podcast_new_popup", methods={"GET", "POST"})
     * @Template("podcast/new_popup.html.twig")
     * @IsGranted("ROLE_CONTENT_ADMIN")
     *
     * @return array|RedirectResponse
     */
    public function new_popup(Request $request) {
        return $this->new($request);
    }

    /**
     * @Route("/{id}", name="podcast_show", methods={"GET"})
     * @Template("podcast/show.html.twig")
     *
     * @return array
     */
    public function show(Podcast $podcast) {
        return [
            'podcast' => $podcast,
        ];
    }

    /**
     * @IsGranted("ROLE_CONTENT_ADMIN")
     * @Route("/{id}/edit", name="podcast_edit", methods={"GET", "POST"})
     *
     * @Template("podcast/edit.html.twig")
     *
     * @return array|RedirectResponse
     */
    public function edit(Request $request, Podcast $podcast) {
        $form = $this->createForm(PodcastType::class, $podcast);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager = $this->getDoctrine()->getManager();

            foreach ($podcast->getContributions() as $contribution) {
                $contribution->setPodcast($podcast);
                if ( ! $entityManager->contains($contribution)) {
                    $entityManager->persist($contribution);
                }
            }
            $this->getDoctrine()->getManager()->flush();
            $this->addFlash('success', 'The updated podcast has been saved.');

            return $this->redirectToRoute('podcast_show', ['id' => $podcast->getId()]);
        }

        return [
            'podcast' => $podcast,
            'form' => $form->createView(),
        ];
    }

    /**
     * @IsGranted("ROLE_CONTENT_ADMIN")
     * @Route("/{id}", name="podcast_delete", methods={"DELETE"})
     *
     * @return RedirectResponse
     */
    public function delete(Request $request, Podcast $podcast) {
        if ($this->isCsrfTokenValid('delete' . $podcast->getId(), $request->request->get('_token'))) {
            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->remove($podcast);
            $entityManager->flush();
            $this->addFlash('success', 'The podcast has been deleted.');
        }

        return $this->redirectToRoute('podcast_index');
    }

    /**
     * @Route("/{id}/new_image", name="podcast_new_image", methods={"GET", "POST"})
     * @IsGranted("ROLE_CONTENT_ADMIN")
     *
     * @Template("podcast/new_image.html.twig")
     *
     * @throws Exception
     *
     * @return array<string,mixed>|RedirectResponse
     */
    public function newImage(Request $request, EntityManagerInterface $em, Podcast $podcast) {
        return $this->newImageAction($request, $em, $podcast, 'podcast_show');
    }

    /**
     * @Route("/{id}/edit_image/{image_id}", name="podcast_edit_image", methods={"GET", "POST"})
     * @ParamConverter("image", options={"id": "image_id"})
     * @IsGranted("ROLE_CONTENT_ADMIN")
     *
     * @throws Exception
     *
     * @return array<string,mixed>|RedirectResponse
     * @Template("podcast/edit_image.html.twig")
     */
    public function editImage(Request $request, EntityManagerInterface $em, Podcast $podcast, Image $image) {
        return $this->editImageAction($request, $em, $podcast, $image, 'podcast_show');
    }

    /**
     * @Route("/{id}/delete_image/{image_id}", name="podcast_delete_image", methods={"DELETE"})
     * @ParamConverter("image", options={"id": "image_id"})
     * @IsGranted("ROLE_CONTENT_ADMIN")
     *
     * @return RedirectResponse
     */
    public function deleteImage(Request $request, EntityManagerInterface $em, Podcast $podcast, Image $image) {
        return $this->deleteImageAction($request, $em, $podcast, $image, 'podcast_show');
    }
}
