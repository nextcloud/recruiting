/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import { createRouter, createWebHashHistory } from 'vue-router'

export default createRouter({
	history: createWebHashHistory(),
	routes: [
		{ path: '/', name: 'home', component: () => import('./views/HomeView.vue') },
		{ path: '/openings/:id(\\d+)', name: 'opening', component: () => import('./views/OpeningView.vue'), props: true },
		{ path: '/triage', name: 'triage', component: () => import('./views/TriageView.vue') },
		{ path: '/my-reviews', name: 'myReviews', component: () => import('./views/MyReviewsView.vue') },
		{ path: '/my-interviews', name: 'myInterviews', component: () => import('./views/MyInterviewsView.vue') },
		{ path: '/templates', name: 'templates', component: () => import('./views/TemplatesView.vue') },
		{ path: '/reports', name: 'reports', component: () => import('./views/ReportsView.vue') },
		{ path: '/pool', name: 'pool', component: () => import('./views/TalentPoolView.vue') },
		// Deep links from notifications/search: resolves the candidate, then
		// redirects to its opening (or triage) and opens the sidebar.
		{ path: '/candidate/:id(\\d+)', name: 'candidate', component: () => import('./views/CandidateRedirect.vue'), props: true },
		{ path: '/:pathMatch(.*)*', redirect: '/' },
	],
})
