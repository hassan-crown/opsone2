import { Loader2Icon } from "lucide-react"

import { useT } from "@/lib/i18n"
import { cn } from "@/lib/utils"

function Spinner({ className, ...props }: React.ComponentProps<"svg">) {
  const t = useT()

  return (
    <Loader2Icon
      role="status"
      aria-label={t("ui.loading")}
      strokeWidth={1.75}
      className={cn("size-5 animate-o1-spin", className)}
      {...props}
    />
  )
}

export { Spinner }
