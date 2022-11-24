<?php

namespace AppBundle\Controller;

use DateTime;
use AppBundle\Entity\Job;
use AppBundle\Entity\Shift;
use AppBundle\Event\ShiftBookedEvent;
use AppBundle\Event\ShiftFreedEvent;
use AppBundle\Event\ShiftValidatedEvent;
use AppBundle\Event\ShiftInvalidatedEvent;
use AppBundle\Form\ShiftType;
use AppBundle\Security\MembershipVoter;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Form\Extension\Core\Type\RadioType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;

/**
 * @Route("shift")
 */
class ShiftController extends Controller
{

    /**
     * @Route("/new", name="shift_new")
     * @Security("has_role('ROLE_SHIFT_MANAGER')")
     * @Method({"GET", "POST"})
     */
    public function newAction(Request $request)
    {
        $session = new Session();
        $shift = new Shift();

        $em = $this->getDoctrine()->getManager();
        $job = $em->getRepository(Job::class)->findOneBy(array());

        if (!$job) {
            $session->getFlashBag()->add('warning', 'Commençons par créer un poste de bénévolat');
            return $this->redirectToRoute('job_new');
        }

        $form = $this->createForm(ShiftType::class, $shift);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $request->request->all();

            if (count($data) === 1){
                $number = array_values($data)[0]["number"];

                while (1 < $number ){
                    $s = clone($shift);
                    $em->persist($s);
                    $number --;
                }
            }

            $em->persist($shift);
            $em->flush();
            $session->getFlashBag()->add('success', 'Le créneau a bien été créé !');
            return $this->redirectToRoute('booking_admin');
        }

        return $this->render('admin/shift/new.html.twig', array(
            "form" => $form->createView()
        ));
    }

    /**
     * Book a shift.
     *
     * @Route("/{id}/book", name="shift_book")
     * @Method("POST")
     */
    public function bookShiftAction(Request $request, Shift $shift): Response
    {
        $session = new Session();

        $content = json_decode($request->getContent());
        $beneficiaryId = $content->beneficiaryId;
        $isFixe = $content->typeService;

        $em = $this->getDoctrine()->getManager();
        $beneficiary = $em->getRepository('AppBundle:Beneficiary')->find($beneficiaryId);

        // Check if the shift is bookable by the given beneficiary
        // Also check if the beneficiary belongs to the same membership as the current user
        if (!$beneficiary
            || !$this->get('shift_service')->isShiftBookable($shift, $beneficiary)
            || !$this->isGranted(MembershipVoter::EDIT, $beneficiary->getMembership())
        ) {
            $session->getFlashBag()->add("error", "Impossible de réserver ce créneau");
            return new Response($this->generateUrl('booking'), 205);
        }

        if (!$shift->getBooker()) {
            $current_user = $this->get('security.token_storage')->getToken()->getUser();
            $shift->setBooker($current_user);
            $shift->setBookedTime(new DateTime('now'));
        }
        $shift->setShifter($beneficiary);
        $shift->setIsDismissed(false);
        $shift->setDismissedReason(null);
        $shift->setDismissedTime(null);
        $shift->setLastShifter(null);
        $shift->setFixe($isFixe);
        $em->persist($shift);

        $member = $beneficiary->getMembership();
        if ($member->getFirstShiftDate() == null) {
            $firstDate = clone($shift->getStart());
            $firstDate->setTime(0, 0, 0);
            $member->setFirstShiftDate($firstDate);
            $em->persist($member);
        }

        $em->flush();

        $dispatcher = $this->get('event_dispatcher');
        $dispatcher->dispatch(ShiftBookedEvent::NAME, new ShiftBookedEvent($shift, false));

        $session->getFlashBag()->add("success", "Ce créneau a bien été réservé !");
        return new Response($this->generateUrl('homepage'), 200);
    }

    /**
     * Book a shift admin.
     *
     * @Route("/{id}/book_admin", name="shift_book_admin")
     * @Security("has_role('ROLE_SHIFT_MANAGER')")
     * @Method("POST")
     */
    public function bookShiftAdminAction(Request $request, Shift $shift)
    {
        $session = new Session();

        $form = $this->createFormBuilder()
            ->add('shifter', TextType::class)
            ->add('fixe', RadioType::class)
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($shift->getShifter() && !$shift->getIsDismissed()) {
                $session->getFlashBag()->add("error", "Désolé, ce créneau est déjà réservé");
                return new Response($this->generateUrl("booking_admin"), 205);
            }

            $fixe = $form->get("fixe")->getData();
            $str = $form->get("shifter")->getData();
            $em = $this->getDoctrine()->getManager();
            // $membership = $em->getRepository('AppBundle:Membership')->findOneFromAutoComplete($str);
            // $beneficiary = $membership->getBeneficiaries()->findOneFromAutoComplete($str);
            $beneficiary = $em->getRepository('AppBundle:Beneficiary')->findOneFromAutoComplete($str);

            if (!$beneficiary) {
                $session->getFlashBag()->add("error", "Impossible de trouve ce béneficiaire 😕");
                return $this->redirectToRoute('booking_admin');
            }

            if ($shift->getFormation() && !$beneficiary->getFormations()->contains($shift->getFormation())) {
                $session->getFlashBag()->add("error", "Désolé, ce bénévole n'a pas la qualification necessaire (" . $shift->getFormation()->getName() . ")");
                return $this->redirectToRoute('booking_admin');
            }

            if (!$shift->getBooker()) {
                $current_user = $this->get('security.token_storage')->getToken()->getUser();
                $shift->setBooker($current_user);
                $shift->setBookedTime(new DateTime('now'));
            }
            $shift->setShifter($beneficiary);
            $shift->setIsDismissed(false);
            $shift->setDismissedReason(null);
            $shift->setDismissedTime(null);
            $shift->setLastShifter(null);
            $shift->setFixe($fixe);

            $em->persist($shift);

            $member = $beneficiary->getMembership();
            if ($member->getFirstShiftDate() == null) {
                $firstDate = clone($shift->getStart());
                $firstDate->setTime(0, 0, 0);
                $member->setFirstShiftDate($firstDate);
                $em->persist($member);
            }
            $em->flush();

            $dispatcher = $this->get('event_dispatcher');
            $dispatcher->dispatch(ShiftBookedEvent::NAME, new ShiftBookedEvent($shift, true));

            $session->getFlashBag()->add("success", "Créneau réservé avec succès pour " . $shift->getShifter());
            return $this->redirectToRoute('booking_admin');
        }

        $session->getFlashBag()->add('error', "Une erreur est survenue...");
        return $this->redirectToRoute('booking_admin');
    }

    /**
     * free a shift.
     *
     * @Route("/{id}/free", name="shift_free")
     * @Method("POST")
     */
    public function freeShiftAction(Request $request, Shift $shift)
    {
        $this->denyAccessUnlessGranted(ShiftVoter::FREE, $shift);

        $session = new Session();

        $membership = $shift->getShifter()->getMembership();

        $em = $this->getDoctrine()->getManager();
        $shift->free();
        $shift->invalidateShiftParticipation();
        $em->persist($shift);
        $em->flush();

        $dispatcher = $this->get('event_dispatcher');
        $dispatcher->dispatch(ShiftFreedEvent::NAME, new ShiftFreedEvent($shift, $membership));

        $session->getFlashBag()->add('success', "Le créneau a bien été libéré");

        $referer = $request->headers->get('referer');
        return new RedirectResponse($referer);

    }

    /**
     * validate a shift.
     *
     * @Route("/{id}/validate", name="shift_validate")
     * @Method("POST")
     */
    public function validateShiftAction(Request $request, Shift $shift)
    {
        $this->denyAccessUnlessGranted(ShiftVoter::VALIDATE, $shift);

        $session = new Session();

        if ($shift->getWasCarriedOut() == 0) {
            $membership = $shift->getShifter()->getMembership();

            $em = $this->getDoctrine()->getManager();
            $shift->validateShiftParticipation();
            $em->persist($shift);
            $em->flush();

            $dispatcher = $this->get('event_dispatcher');
            $dispatcher->dispatch(ShiftValidatedEvent::NAME, new ShiftValidatedEvent($shift));

            $session->getFlashBag()->add('success', "La participation au créneau a bien été validée");
        } else {
            $session->getFlashBag()->add('error', "La participation au créneau a déjà été validée");
        }

        $referer = $request->headers->get('referer');
        return new RedirectResponse($referer);
    }

    /**
     * invalidate a shift.
     *
     * @Route("/{id}/invalidate", name="shift_invalidate")
     * @Method("POST")
     */
    public function invalidateShiftAction(Request $request, Shift $shift)
    {
        $this->denyAccessUnlessGranted(ShiftVoter::INVALIDATE, $shift);

        $session = new Session();

        if ($shift->getWasCarriedOut() == 1) {
            $membership = $shift->getShifter()->getMembership();

            $em = $this->getDoctrine()->getManager();
            $shift->invalidateShiftParticipation();
            $em->persist($shift);
            $em->flush();

            $dispatcher = $this->get('event_dispatcher');
            $dispatcher->dispatch(ShiftInvalidatedEvent::NAME, new ShiftInvalidatedEvent($shift, $membership));

            $session->getFlashBag()->add('success', "La participation au créneau a bien été invalidée");
        } else {
            $session->getFlashBag()->add('error', "La participation au créneau a déjà été invalidée");
        }

        $referer = $request->headers->get('referer');
        return new RedirectResponse($referer);
    }

    /**
     * remove a shift.
     *
     * @Route("/{id}", name="shift_delete")
     * @Security("has_role('ROLE_SHIFT_MANAGER')")
     * @Method("DELETE")
     */
    public function removeShiftAction(Request $request, Shift $shift)
    {
        $session = new Session();

        $form = $this->createDeleteForm($shift);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $this->getDoctrine()->getManager();
            $em->remove($shift);
            $em->flush();
            $session->getFlashBag()->add('success', 'Le créneau a bien été supprimé !');
        }

        return $this->redirectToRoute('booking_admin');
    }

    /**
     * Creates a form to delete a shift entity.
     *
     * @param Shift $shift The shift entity
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm(Shift $shift)
    {
        return $this->createFormBuilder()
            ->setAction($this->generateUrl('shift_delete', array('id' => $shift->getId())))
            ->setMethod('DELETE')
            ->getForm();
    }
}
