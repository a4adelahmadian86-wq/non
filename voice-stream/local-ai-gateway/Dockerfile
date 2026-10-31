FROM node:22-bookworm-slim
ENV NODE_ENV=production
WORKDIR /app
COPY server.mjs ./
EXPOSE 7008
CMD ["node", "server.mjs"]
