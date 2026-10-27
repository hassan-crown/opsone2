import {
  CircleAlertIcon,
  CircleCheckIcon,
  CircleXIcon,
  InfoIcon,
  XIcon,
} from "lucide-react"
import { Toaster as Sonner, type ToasterProps } from "sonner"

import { useAppearance } from "@/hooks/use-appearance"
import { useFlashToast } from "@/hooks/use-flash-toast"
import { useLocale } from "@/lib/i18n"

/**
 * Toasts sit at the end of the line (bottom-left in RTL, bottom-right in LTR).
 * Success/info dismiss after 5s; errors stay until dismissed.
 */
function Toaster({ ...props }: ToasterProps) {
  const { resolvedAppearance } = useAppearance()
  const { dir } = useLocale()

  useFlashToast()

  return (
    <Sonner
      theme={resolvedAppearance === "dark" ? "dark" : "light"}
      dir={dir}
      position={dir === "rtl" ? "bottom-left" : "bottom-right"}
      visibleToasts={3}
      duration={5000}
      closeButton
      icons={{
        success: <CircleCheckIcon className="size-5 text-success-text" strokeWidth={1.75} />,
        info: <InfoIcon className="size-5 text-info-text" strokeWidth={1.75} />,
        warning: <CircleAlertIcon className="size-5 text-warning-text" strokeWidth={1.75} />,
        error: <CircleXIcon className="size-5 text-danger-text" strokeWidth={1.75} />,
        close: <XIcon className="size-4" />,
      }}
      toastOptions={{
        classNames: {
          toast:
            "!rounded-md !border !border-stroke !bg-raised !text-strong !shadow-raised !font-[inherit] !text-body",
          error: "!border-danger-text",
          description: "!text-weak",
        },
      }}
      {...props}
    />
  )
}

export { Toaster }
