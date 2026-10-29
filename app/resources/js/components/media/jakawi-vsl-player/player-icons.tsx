// Lucide v0.475.0 geometry; see LICENSE.lucide. No additional icon dependency.
import { createElement, type ReactNode, type SVGProps } from 'react';
type IconProps = SVGProps<SVGSVGElement> & { size?: number };
function Icon({size = 24, strokeWidth = 2, children, ...props}: IconProps & {children?: ReactNode}) { return <svg xmlns="http://www.w3.org/2000/svg" width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth={strokeWidth} strokeLinecap="round" strokeLinejoin="round" {...props}>{children}</svg>; }
export const Pause = (props: IconProps) => <Icon {...props}>{([
  ["rect", { x: "14", y: "4", width: "4", height: "16", rx: "1", key: "zuxfzm" }],
  ["rect", { x: "6", y: "4", width: "4", height: "16", rx: "1", key: "1okwgv" }]
] as [string, Record<string, string>][]).map(([tag, attributes]) => createElement(tag, attributes))}</Icon>;
export const Play = (props: IconProps) => <Icon {...props}>{([["polygon", { points: "6 3 20 12 6 21 6 3", key: "1oa8hb" }]] as [string, Record<string, string>][]).map(([tag, attributes]) => createElement(tag, attributes))}</Icon>;
export const RotateCcw = (props: IconProps) => <Icon {...props}>{([
  ["path", { d: "M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8", key: "1357e3" }],
  ["path", { d: "M3 3v5h5", key: "1xhq8a" }]
] as [string, Record<string, string>][]).map(([tag, attributes]) => createElement(tag, attributes))}</Icon>;
export const Volume2 = (props: IconProps) => <Icon {...props}>{([
  [
    "path",
    {
      d: "M11 4.702a.705.705 0 0 0-1.203-.498L6.413 7.587A1.4 1.4 0 0 1 5.416 8H3a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h2.416a1.4 1.4 0 0 1 .997.413l3.383 3.384A.705.705 0 0 0 11 19.298z",
      key: "uqj9uw"
    }
  ],
  ["path", { d: "M16 9a5 5 0 0 1 0 6", key: "1q6k2b" }],
  ["path", { d: "M19.364 18.364a9 9 0 0 0 0-12.728", key: "ijwkga" }]
] as [string, Record<string, string>][]).map(([tag, attributes]) => createElement(tag, attributes))}</Icon>;
