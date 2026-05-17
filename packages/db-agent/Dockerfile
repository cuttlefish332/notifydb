FROM node:20-alpine

WORKDIR /agent
COPY package.json ./
RUN npm install --omit=dev
COPY bin ./bin
COPY src ./src

ENTRYPOINT ["node", "bin/notifydb-db-agent.js"]
CMD ["run"]
