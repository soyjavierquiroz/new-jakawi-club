// Closed editorial vocabulary; keep aligned with LandingContent::BENEFIT_ICONS.
export const benefitIconOptions = [["play", "Reproducir"], ["users", "Comunidad"], ["gift", "Regalo"], ["compass", "Brújula"], ["calendar", "Calendario"], ["star", "Estrella"], ["heart", "Corazón"], ["arrow-right", "Flecha derecha"], ["lightbulb", "Idea"], ["megaphone", "Megáfono"]] as const;
export type BenefitIconName = typeof benefitIconOptions[number][0];
