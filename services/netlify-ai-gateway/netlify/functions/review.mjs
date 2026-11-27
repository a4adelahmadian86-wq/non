import { proxyChat } from "./proxy-chat.mjs";

export default async function review(request) {
  return proxyChat(request, "review");
}
